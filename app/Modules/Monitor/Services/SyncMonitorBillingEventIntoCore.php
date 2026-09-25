<?php

namespace App\Modules\Monitor\Services;

use App\Core\Models\BillingCustomer;
use App\Core\Models\CurrentProductSubscription;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\ProductBillingEvent;
use App\Core\Models\ProductSubscription;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Modules\Monitor\Models\BillingEvent as MonitorBillingEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Projects Monitor's verified Stripe event state into Monitor's separate Core subscription slot. */
final class SyncMonitorBillingEventIntoCore
{
    private const PROVIDER_ACCOUNT = 'monitor';

    public function __construct(
        private readonly StripeBillingClient $stripe,
        private readonly MonitorPlanSnapshot $snapshots,
    ) {}

    /** @param array<string, mixed> $event */
    public function handle(array $event): bool
    {
        $eventId = $event['id'] ?? null;
        $eventType = $event['type'] ?? null;
        $createdAt = is_numeric($event['created'] ?? null) ? max(0, (int) $event['created']) : null;
        $object = $event['data']['object'] ?? [];

        if (! is_string($eventId) || $eventId === '' || ! is_string($eventType) || $eventType === '') {
            throw new InvalidArgumentException('The Monitor billing event is missing its identity.');
        }

        if (! is_array($object)) {
            $object = [];
        }

        $sourceEvent = MonitorBillingEvent::query()->where('stripe_event_id', $eventId)->first();

        return DB::connection('core')->transaction(function () use ($eventId, $eventType, $createdAt, $object, $sourceEvent): bool {
            $billingEvent = ProductBillingEvent::query()
                ->where('provider', 'stripe')
                ->where('provider_account_key', self::PROVIDER_ACCOUNT)
                ->where('provider_event_id', $eventId)
                ->lockForUpdate()
                ->first();

            if ($billingEvent !== null
                && ! in_array($billingEvent->processing_status, ['pending_reconciliation', 'needs_review'], true)) {
                return false;
            }

            if (! $this->supported($eventType)) {
                $this->saveEvent($billingEvent, null, $eventId, $eventType, $createdAt, 'ignored', 'unsupported_event_type', []);

                return true;
            }

            if ($sourceEvent?->processing_status === MonitorBillingEvent::STATUS_IGNORED) {
                $this->saveEvent(
                    $billingEvent,
                    $billingEvent?->workspace_id,
                    $eventId,
                    $eventType,
                    $createdAt,
                    'ignored',
                    $sourceEvent->ignored_reason ?? 'source_event_ignored',
                    $this->safeMetadata($object),
                );

                return true;
            }

            [$workspace, $workspaceReason] = $this->resolveWorkspace($object, $sourceEvent);
            if ($workspace === null) {
                $this->saveEvent(
                    $billingEvent,
                    null,
                    $eventId,
                    $eventType,
                    $createdAt,
                    'pending_reconciliation',
                    $workspaceReason ?? 'workspace_mapping_missing',
                    $this->safeMetadata($object),
                );

                return true;
            }

            $workspace = CoreWorkspace::query()->whereKey($workspace->getKey())->lockForUpdate()->first();
            if ($workspace === null) {
                $this->saveEvent($billingEvent, null, $eventId, $eventType, $createdAt, 'pending_reconciliation', 'workspace_missing', $this->safeMetadata($object));

                return true;
            }

            $position = $this->subscriptionId($object);
            $subscription = $position === null
                ? null
                : ProductSubscription::query()
                    ->where('provider', 'stripe')
                    ->where('provider_account_key', self::PROVIDER_ACCOUNT)
                    ->where('provider_subscription_id', $position)
                    ->lockForUpdate()
                    ->first();

            if ($subscription !== null
                && ((string) $subscription->workspace_id !== (string) $workspace->getKey()
                    || $subscription->product !== 'monitor')) {
                $this->saveEvent(
                    $billingEvent,
                    (string) $workspace->getKey(),
                    $eventId,
                    $eventType,
                    $createdAt,
                    'needs_review',
                    'subscription_mapping_conflict',
                    $this->safeMetadata($object),
                );

                return true;
            }

            $current = CurrentProductSubscription::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('product', 'monitor')
                ->with('subscription')
                ->lockForUpdate()
                ->first();

            if ($subscription === null && $position === null && $current?->subscription instanceof ProductSubscription) {
                $subscription = $current->subscription;
            }

            if ($this->isStale($subscription, $createdAt, $eventId)) {
                $this->saveEvent(
                    $billingEvent,
                    (string) $workspace->getKey(),
                    $eventId,
                    $eventType,
                    $createdAt,
                    'ignored',
                    'stale_event',
                    $this->safeMetadata($object),
                );

                return true;
            }

            $projection = $this->projection($eventType, $object, $subscription);
            if ($projection === null) {
                $this->saveEvent(
                    $billingEvent,
                    (string) $workspace->getKey(),
                    $eventId,
                    $eventType,
                    $createdAt,
                    'needs_review',
                    'plan_or_subscription_unrecognized',
                    $this->safeMetadata($object),
                );

                return true;
            }

            if ($this->conflictsWithCurrent($current, $subscription, $projection['provider_subscription_id'], $projection['status'])) {
                $this->saveEvent(
                    $billingEvent,
                    (string) $workspace->getKey(),
                    $eventId,
                    $eventType,
                    $createdAt,
                    'needs_review',
                    'multiple_current_monitor_subscriptions',
                    $this->safeMetadata($object),
                );

                return true;
            }

            $customer = $this->resolveBillingCustomer($workspace, $projection['provider_customer_id']);
            if ($customer === false) {
                $this->saveEvent(
                    $billingEvent,
                    (string) $workspace->getKey(),
                    $eventId,
                    $eventType,
                    $createdAt,
                    'needs_review',
                    'billing_customer_mapping_conflict',
                    $this->safeMetadata($object),
                );

                return true;
            }

            $subscription = $this->persistSubscription($workspace, $subscription, $customer, $projection, $createdAt, $eventId, $eventType);
            $current = $this->updateCurrentAssignment($workspace, $current, $subscription, $projection, $createdAt, $eventId);

            $this->reconcileCurrentSubscriptionMap($sourceEvent?->workspace_id, $workspace, $current);
            $this->saveEvent(
                $billingEvent,
                (string) $workspace->getKey(),
                $eventId,
                $eventType,
                $createdAt,
                'applied',
                null,
                $this->safeMetadata($object),
            );

            return true;
        }, attempts: 3);
    }

    private function supported(string $eventType): bool
    {
        return in_array($eventType, [
            'checkout.session.completed',
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted',
            'invoice.paid',
            'invoice.payment_failed',
        ], true);
    }

    /** @param array<string, mixed> $object
     * @return array{0: ?CoreWorkspace, 1: ?string}
     */
    private function resolveWorkspace(array $object, ?MonitorBillingEvent $sourceEvent): array
    {
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
        $coreWorkspaceId = $metadata['core_workspace_id'] ?? null;
        $sourceWorkspaceId = $sourceEvent?->workspace_id
            ?? $metadata['workspace_id']
            ?? (is_string($coreWorkspaceId) ? null : ($object['client_reference_id'] ?? null));
        $mappedWorkspaceId = null;

        if (is_scalar($sourceWorkspaceId) && trim((string) $sourceWorkspaceId) !== '') {
            $map = LegacyIdentityMap::query()
                ->where('source_product', 'monitor')
                ->where('source_entity', 'workspace')
                ->where('source_id', trim((string) $sourceWorkspaceId))
                ->first();

            if ($map !== null && ($map->status !== 'reconciled' || $map->canonical_entity !== 'workspace' || $map->canonical_id === null)) {
                return [null, 'workspace_mapping_not_reconciled'];
            }

            $mappedWorkspaceId = $map?->canonical_id;
        }

        if (is_string($coreWorkspaceId) && $coreWorkspaceId !== '') {
            if ($mappedWorkspaceId !== null && $mappedWorkspaceId !== $coreWorkspaceId) {
                return [null, 'workspace_mapping_conflict'];
            }

            $workspace = CoreWorkspace::query()->find($coreWorkspaceId);
            if ($workspace !== null) {
                return [$workspace, null];
            }
        }

        $resolvedWorkspaceId = $mappedWorkspaceId;
        if ($resolvedWorkspaceId === null) {
            $subscriptionId = $this->subscriptionId($object);
            if ($subscriptionId !== null) {
                $matches = ProductSubscription::query()
                    ->where('provider', 'stripe')
                    ->where('provider_account_key', self::PROVIDER_ACCOUNT)
                    ->where('provider_subscription_id', $subscriptionId)
                    ->where('product', 'monitor')
                    ->distinct()
                    ->pluck('workspace_id')
                    ->unique();
                $resolvedWorkspaceId = $matches->count() === 1 ? (string) $matches->first() : null;
                if ($matches->count() > 1) {
                    return [null, 'subscription_workspace_ambiguous'];
                }
            }
        }

        if ($resolvedWorkspaceId === null) {
            $customerId = $this->stringValue($object['customer'] ?? null);
            if ($customerId !== null) {
                $matches = BillingCustomer::query()
                    ->where('provider', 'stripe')
                    ->where('provider_account_key', self::PROVIDER_ACCOUNT)
                    ->where('provider_customer_id', $customerId)
                    ->whereNotNull('workspace_id')
                    ->distinct()
                    ->pluck('workspace_id')
                    ->unique();
                $resolvedWorkspaceId = $matches->count() === 1 ? (string) $matches->first() : null;
                if ($matches->count() > 1) {
                    return [null, 'billing_customer_workspace_ambiguous'];
                }
            }
        }

        return $resolvedWorkspaceId === null
            ? [null, 'workspace_mapping_missing']
            : [CoreWorkspace::query()->find($resolvedWorkspaceId), null];
    }

    /** @param array<string, mixed> $object
     * @return array<string, mixed>|null
     */
    private function projection(string $eventType, array $object, ?ProductSubscription $subscription): ?array
    {
        $subscriptionId = $this->subscriptionId($object) ?? $subscription?->provider_subscription_id;
        $customerId = $this->referenceId($object['customer'] ?? null) ?? $subscription?->billingCustomer?->provider_customer_id;
        $reportedPriceId = $this->priceId($object);
        $priceId = $reportedPriceId ?? $subscription?->provider_price_id;
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
        $metadataPlan = is_string($metadata['plan'] ?? null) ? $metadata['plan'] : null;
        $planKey = $reportedPriceId !== null
            ? $this->stripe->planForPrice($reportedPriceId)
            : ($metadataPlan ?? $subscription?->plan_key);

        if ($subscriptionId === null || $planKey === null || $this->snapshots->forPlan($planKey) === null) {
            return null;
        }

        $status = match ($eventType) {
            'checkout.session.completed' => in_array((string) ($object['payment_status'] ?? ''), ['paid', 'no_payment_required'], true)
                ? 'active'
                : 'incomplete',
            'customer.subscription.created', 'customer.subscription.updated' => $this->stripeStatus($object['status'] ?? null),
            'customer.subscription.deleted' => 'canceled',
            'invoice.paid' => 'active',
            'invoice.payment_failed' => 'past_due',
            default => null,
        };

        if ($status === null) {
            return null;
        }

        return [
            'provider_subscription_id' => $subscriptionId,
            'provider_customer_id' => $customerId,
            'provider_price_id' => $priceId,
            'plan_key' => $planKey,
            'plan_snapshot' => $this->snapshots->forPlan($planKey),
            'status' => $status,
            'quantity' => max(1, (int) ($this->firstSubscriptionItem($object)['quantity'] ?? $object['quantity'] ?? $subscription?->quantity ?? 1)),
            'current_period_starts_at' => $this->timestamp($object['current_period_start'] ?? null) ?? $subscription?->current_period_starts_at,
            'current_period_ends_at' => $this->timestamp($object['current_period_end'] ?? null) ?? $subscription?->current_period_ends_at,
            'trial_ends_at' => $this->timestamp($object['trial_end'] ?? null) ?? $subscription?->trial_ends_at,
            'cancel_at' => $this->timestamp($object['cancel_at'] ?? null),
            'cancel_at_period_end' => (bool) ($object['cancel_at_period_end'] ?? false),
            'canceled_at' => $this->timestamp($object['canceled_at'] ?? null),
        ];
    }

    private function conflictsWithCurrent(
        ?CurrentProductSubscription $current,
        ?ProductSubscription $incoming,
        string $incomingSubscriptionId,
        string $incomingStatus,
    ): bool {
        if (! in_array($incomingStatus, ['active', 'trialing', 'past_due'], true)
            || ! $current?->subscription instanceof ProductSubscription) {
            return false;
        }

        $existing = $current->subscription;
        if ($incoming !== null && (string) $incoming->getKey() === (string) $existing->getKey()) {
            return false;
        }

        return ! $this->isFreeFallback($existing)
            && $existing->status !== 'canceled'
            && $existing->status !== 'incomplete_expired'
            && $existing->provider_subscription_id !== $incomingSubscriptionId;
    }

    private function isFreeFallback(ProductSubscription $subscription): bool
    {
        return $subscription->plan_key === 'free'
            && in_array($subscription->provider, ['monitor_legacy', 'legacy_access'], true);
    }

    private function resolveBillingCustomer(CoreWorkspace $workspace, ?string $providerCustomerId): BillingCustomer|false|null
    {
        if ($providerCustomerId === null) {
            return null;
        }

        $customer = BillingCustomer::query()
            ->where('provider', 'stripe')
            ->where('provider_account_key', self::PROVIDER_ACCOUNT)
            ->where('provider_customer_id', $providerCustomerId)
            ->lockForUpdate()
            ->first();

        if ($customer !== null && $customer->workspace_id !== null
            && (string) $customer->workspace_id !== (string) $workspace->getKey()) {
            return false;
        }

        if ($customer === null) {
            return BillingCustomer::query()->create([
                'workspace_id' => $workspace->getKey(),
                'provider' => 'stripe',
                'provider_account_key' => self::PROVIDER_ACCOUNT,
                'provider_customer_id' => $providerCustomerId,
                'status' => 'active',
                'metadata' => ['source' => 'monitor_stripe_webhook'],
            ]);
        }

        if ($customer->workspace_id === null) {
            $otherWorkspaceSubscription = ProductSubscription::query()
                ->where('billing_customer_id', $customer->getKey())
                ->where(fn ($query) => $query
                    ->where('workspace_id', '!=', $workspace->getKey())
                    ->orWhere('product', '!=', 'monitor'))
                ->exists();

            if ($otherWorkspaceSubscription) {
                return false;
            }

            $customer->forceFill(['workspace_id' => $workspace->getKey()])->save();
        }

        return $customer;
    }

    /** @param array<string, mixed> $projection */
    private function persistSubscription(
        CoreWorkspace $workspace,
        ?ProductSubscription $subscription,
        BillingCustomer|false|null $customer,
        array $projection,
        ?int $createdAt,
        string $eventId,
        string $eventType,
    ): ProductSubscription {
        if ($subscription === null) {
            $subscription = ProductSubscription::query()->create([
                'workspace_id' => $workspace->getKey(),
                'billing_customer_id' => $customer instanceof BillingCustomer ? $customer->getKey() : null,
                'product' => 'monitor',
                'provider' => 'stripe',
                'provider_account_key' => self::PROVIDER_ACCOUNT,
                'provider_subscription_id' => $projection['provider_subscription_id'],
                'provider_price_id' => $projection['provider_price_id'],
                'plan_key' => $projection['plan_key'],
                'status' => $projection['status'],
                'quantity' => $projection['quantity'],
                'trial_ends_at' => $projection['trial_ends_at'],
                'current_period_starts_at' => $projection['current_period_starts_at'],
                'current_period_ends_at' => $projection['current_period_ends_at'],
                'cancel_at' => $this->cancelAt($projection),
                'canceled_at' => $projection['canceled_at'],
                'metadata' => [],
            ]);
        }

        $metadata = is_array($subscription->metadata) ? $subscription->metadata : [];
        $billingState = is_array($metadata['billing_state'] ?? null) ? $metadata['billing_state'] : [];
        $metadata['plan_snapshot'] = $projection['plan_snapshot'];
        $metadata['billing_state'] = array_merge($billingState, [
            'event_position' => ['created_at' => $createdAt, 'event_id' => $eventId],
            'last_event_type' => $eventType,
            'provider_account_key' => self::PROVIDER_ACCOUNT,
        ]);

        $subscription->forceFill([
            'billing_customer_id' => $customer instanceof BillingCustomer ? $customer->getKey() : $subscription->billing_customer_id,
            'provider_price_id' => $projection['provider_price_id'] ?? $subscription->provider_price_id,
            'plan_key' => $projection['plan_key'],
            'status' => $projection['status'],
            'quantity' => $projection['quantity'],
            'trial_ends_at' => $projection['trial_ends_at'],
            'current_period_starts_at' => $projection['current_period_starts_at'],
            'current_period_ends_at' => $projection['current_period_ends_at'],
            'cancel_at' => $this->cancelAt($projection),
            'canceled_at' => $projection['canceled_at'] ?? ($projection['status'] === 'canceled' ? now('UTC') : null),
            'metadata' => $metadata,
        ])->save();

        return $subscription;
    }

    /** @param array<string, mixed> $projection */
    private function updateCurrentAssignment(
        CoreWorkspace $workspace,
        ?CurrentProductSubscription $current,
        ProductSubscription $subscription,
        array $projection,
        ?int $createdAt,
        string $eventId,
    ): CurrentProductSubscription {
        if (! $this->mayReplaceCurrent($current, $subscription, $projection['status'])) {
            return $current;
        }

        if (in_array($projection['status'], ['canceled', 'unpaid', 'incomplete', 'incomplete_expired'], true)) {
            $subscription = $this->freeSubscription($workspace, $createdAt, $eventId);
        } elseif (! in_array($projection['status'], ['active', 'trialing', 'past_due'], true)) {
            return $current ?? CurrentProductSubscription::query()->firstOrCreate([
                'workspace_id' => $workspace->getKey(),
                'product' => 'monitor',
            ], ['product_subscription_id' => $subscription->getKey()]);
        }

        if ($current === null) {
            return CurrentProductSubscription::query()->create([
                'workspace_id' => $workspace->getKey(),
                'product' => 'monitor',
                'product_subscription_id' => $subscription->getKey(),
            ]);
        }

        if ((string) $current->product_subscription_id !== (string) $subscription->getKey()) {
            $current->forceFill(['product_subscription_id' => $subscription->getKey()])->save();
        }

        return $current;
    }

    private function mayReplaceCurrent(
        ?CurrentProductSubscription $current,
        ProductSubscription $incoming,
        string $incomingStatus,
    ): bool {
        if ($current === null) {
            return true;
        }

        if ((string) $current->product_subscription_id === (string) $incoming->getKey()) {
            return true;
        }

        $existing = $current->subscription;

        return $existing instanceof ProductSubscription
            && $this->isFreeFallback($existing)
            && in_array($incomingStatus, ['active', 'trialing', 'past_due'], true);
    }

    private function freeSubscription(CoreWorkspace $workspace, ?int $createdAt, string $eventId): ProductSubscription
    {
        $snapshot = $this->snapshots->forPlan('free');
        if ($snapshot === null) {
            throw new InvalidArgumentException('The Monitor free plan snapshot is unavailable.');
        }

        $subscription = ProductSubscription::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('product', 'monitor')
            ->where('provider', 'monitor_legacy')
            ->where('provider_account_key', self::PROVIDER_ACCOUNT)
            ->whereNull('provider_subscription_id')
            ->where('plan_key', 'free')
            ->lockForUpdate()
            ->first();

        if ($subscription === null) {
            $subscription = ProductSubscription::query()->create([
                'workspace_id' => $workspace->getKey(),
                'billing_customer_id' => null,
                'product' => 'monitor',
                'provider' => 'monitor_legacy',
                'provider_account_key' => self::PROVIDER_ACCOUNT,
                'provider_subscription_id' => null,
                'provider_price_id' => null,
                'plan_key' => 'free',
                'status' => 'active',
                'quantity' => 1,
                'metadata' => [
                    'entitlement_snapshot' => true,
                    'plan_snapshot' => $snapshot,
                    'billing_state' => ['source' => 'monitor_free_fallback'],
                ],
            ]);
        } else {
            $metadata = is_array($subscription->metadata) ? $subscription->metadata : [];
            $metadata['plan_snapshot'] = $snapshot;
            $metadata['billing_state'] = array_merge(
                is_array($metadata['billing_state'] ?? null) ? $metadata['billing_state'] : [],
                ['source' => 'monitor_free_fallback', 'event_position' => ['created_at' => $createdAt, 'event_id' => $eventId]],
            );
            $subscription->forceFill([
                'status' => 'active',
                'plan_key' => 'free',
                'billing_customer_id' => null,
                'current_period_starts_at' => null,
                'current_period_ends_at' => null,
                'cancel_at' => null,
                'canceled_at' => null,
                'metadata' => $metadata,
            ])->save();
        }

        return $subscription;
    }

    private function reconcileCurrentSubscriptionMap(?int $sourceWorkspaceId, CoreWorkspace $workspace, CurrentProductSubscription $current): void
    {
        if ($sourceWorkspaceId === null) {
            return;
        }

        $workspaceMapping = LegacyIdentityMap::query()
            ->where('source_product', 'monitor')
            ->where('source_entity', 'workspace')
            ->where('source_id', (string) $sourceWorkspaceId)
            ->first();

        if ($workspaceMapping?->status !== 'reconciled'
            || $workspaceMapping->canonical_entity !== 'workspace'
            || (string) $workspaceMapping->canonical_id !== (string) $workspace->getKey()) {
            return;
        }

        $mapping = LegacyIdentityMap::query()
            ->where('source_product', 'monitor')
            ->where('source_entity', 'current_subscription')
            ->where('source_id', (string) $sourceWorkspaceId)
            ->lockForUpdate()
            ->first();

        $attributes = [
            'canonical_entity' => 'current_product_subscription',
            'canonical_id' => (string) $current->getKey(),
            'status' => 'reconciled',
            'batch_key' => 'monitor-live-billing-webhook-v1',
            'reconciliation_notes' => null,
            'metadata' => [
                'product_subscription_id' => (string) $current->product_subscription_id,
                'workspace_id' => (string) $workspace->getKey(),
                'updated_by' => 'monitor_stripe_webhook',
            ],
            'reconciled_at' => now('UTC'),
        ];

        if ($mapping === null) {
            LegacyIdentityMap::query()->create([
                'source_product' => 'monitor',
                'source_entity' => 'current_subscription',
                'source_id' => (string) $sourceWorkspaceId,
                ...$attributes,
            ]);

            return;
        }

        if ($mapping->status !== 'reconciled'
            || $mapping->canonical_entity !== 'current_product_subscription'
            || (string) $mapping->canonical_id !== (string) $current->getKey()) {
            return;
        }

        $mapping->fill($attributes)->save();
    }

    private function isStale(?ProductSubscription $subscription, ?int $createdAt, string $eventId): bool
    {
        if ($subscription === null || $createdAt === null) {
            return false;
        }

        $metadata = $subscription->metadata;
        $billingState = is_array($metadata) && is_array($metadata['billing_state'] ?? null)
            ? $metadata['billing_state']
            : [];
        $position = $billingState['event_position'] ?? $billingState['source_billing_event_position'] ?? [];
        $previousCreatedAt = is_array($position) && is_numeric($position['created_at'] ?? null)
            ? (int) $position['created_at']
            : null;
        $previousEventId = is_array($position) && is_string($position['event_id'] ?? null)
            ? $position['event_id']
            : null;

        return $previousCreatedAt !== null
            && ($createdAt < $previousCreatedAt
                || ($createdAt === $previousCreatedAt && $previousEventId !== null && strcmp($eventId, $previousEventId) <= 0));
    }

    /** @return array<string, mixed> */
    private function safeMetadata(array $object): array
    {
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
        $item = $this->firstSubscriptionItem($object);

        return array_filter([
            'source_workspace_id' => $metadata['workspace_id'] ?? $object['client_reference_id'] ?? null,
            'canonical_workspace_id' => $metadata['core_workspace_id'] ?? null,
            'provider_customer_id' => $this->referenceId($object['customer'] ?? null),
            'provider_subscription_id' => $this->subscriptionId($object),
            'provider_price_id' => $this->priceId($object),
            'plan_key' => $metadata['plan'] ?? $this->stripe->planForPrice($this->priceId($object)),
            'provider_status' => $this->stringValue($object['status'] ?? $object['payment_status'] ?? null),
            'first_item_quantity' => is_numeric($item['quantity'] ?? null)
                ? max(1, (int) $item['quantity'])
                : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function saveEvent(
        ?ProductBillingEvent $existing,
        ?string $workspaceId,
        string $eventId,
        string $eventType,
        ?int $createdAt,
        string $status,
        ?string $reason,
        array $metadata,
    ): void {
        $attributes = [
            'workspace_id' => $workspaceId,
            'product' => 'monitor',
            'provider' => 'stripe',
            'provider_account_key' => self::PROVIDER_ACCOUNT,
            'provider_event_id' => $eventId,
            'event_type' => $eventType,
            'provider_created_at' => $createdAt,
            'processing_status' => $status,
            'ignored_reason' => $reason,
            'processed_at' => now('UTC'),
            'metadata' => $metadata,
        ];

        if ($existing === null) {
            ProductBillingEvent::query()->create($attributes);

            return;
        }

        $existing->forceFill($attributes)->save();
    }

    /** @param array<string, mixed> $projection */
    private function cancelAt(array $projection): ?CarbonImmutable
    {
        if ($projection['cancel_at'] instanceof CarbonImmutable) {
            return $projection['cancel_at'];
        }

        return $projection['cancel_at_period_end']
            ? $projection['current_period_ends_at']
            : null;
    }

    /** @param array<string, mixed> $object */
    private function subscriptionId(array $object): ?string
    {
        $id = $this->referenceId($object['subscription'] ?? null);
        if ($id === null) {
            $id = ($object['object'] ?? null) === 'subscription' ? ($object['id'] ?? null) : null;
        }

        return $this->stringValue($id);
    }

    /** @param array<string, mixed> $object */
    private function priceId(array $object): ?string
    {
        $item = $this->firstSubscriptionItem($object);
        $priceId = $this->referenceId($item['price'] ?? null);

        if ($priceId !== null) {
            return $priceId;
        }

        $lines = $object['lines']['data'] ?? [];
        $line = is_array($lines) && is_array($lines[0] ?? null) ? $lines[0] : [];
        $priceId = $this->referenceId($line['price'] ?? null)
            ?? $this->referenceId(data_get($line, 'pricing.price_details.price'));

        return $this->stringValue($priceId);
    }

    /** @param array<string, mixed> $object
     * @return array<string, mixed>
     */
    private function firstSubscriptionItem(array $object): array
    {
        $items = $object['items']['data'] ?? [];

        return is_array($items) && is_array($items[0] ?? null) ? $items[0] : [];
    }

    private function referenceId(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['id'] ?? null;
        }

        return $this->stringValue($value);
    }

    private function stripeStatus(mixed $status): ?string
    {
        return is_string($status)
            && in_array($status, ['active', 'trialing', 'past_due', 'canceled', 'unpaid', 'incomplete', 'incomplete_expired', 'paused'], true)
            ? $status
            : null;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        return is_numeric($value) ? CarbonImmutable::createFromTimestampUTC((int) $value) : null;
    }
}
