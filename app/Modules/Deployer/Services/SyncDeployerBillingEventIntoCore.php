<?php

namespace App\Modules\Deployer\Services;

use App\Core\Models\BillingCustomer;
use App\Core\Models\CurrentProductSubscription;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\ProductBillingEvent;
use App\Core\Models\ProductSubscription;
use App\Core\Models\Workspace as CoreWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Projects Cashier-verified Deployer Stripe events into Deployer's Core workspace subscription slot. */
final class SyncDeployerBillingEventIntoCore
{
    private const PRODUCT = 'deployer';

    private const PROVIDER_ACCOUNT = 'deployer';

    public function __construct(
        private readonly DeployerStripeBillingClient $stripe,
        private readonly DeployerPlanSnapshot $snapshots,
    ) {}

    /** @param array<string, mixed> $event */
    public function handle(array $event): bool
    {
        $eventId = $event['id'] ?? null;
        $eventType = $event['type'] ?? null;
        $createdAt = is_numeric($event['created'] ?? null) ? max(0, (int) $event['created']) : null;
        $object = $event['data']['object'] ?? [];

        if (! is_string($eventId) || $eventId === '' || ! is_string($eventType) || $eventType === '') {
            throw new InvalidArgumentException('The Deployer billing event is missing its identity.');
        }

        if (! is_array($object)) {
            $object = [];
        }

        return DB::connection('core')->transaction(function () use ($eventId, $eventType, $createdAt, $object): bool {
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

            [$workspace, $sourceOrganizationId, $workspaceReason] = $this->resolveWorkspace($object);
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

            $providerSubscriptionId = $this->subscriptionId($object);
            $subscription = $providerSubscriptionId === null
                ? null
                : ProductSubscription::query()
                    ->where('provider', 'stripe')
                    ->where('provider_account_key', self::PROVIDER_ACCOUNT)
                    ->where('provider_subscription_id', $providerSubscriptionId)
                    ->lockForUpdate()
                    ->first();

            if ($subscription !== null
                && ((string) $subscription->workspace_id !== (string) $workspace->getKey()
                    || $subscription->product !== self::PRODUCT)) {
                $this->saveEvent($billingEvent, (string) $workspace->getKey(), $eventId, $eventType, $createdAt, 'needs_review', 'subscription_mapping_conflict', $this->safeMetadata($object));

                return true;
            }

            $current = CurrentProductSubscription::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('product', self::PRODUCT)
                ->with('subscription')
                ->lockForUpdate()
                ->first();

            if (in_array($eventType, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)
                && $subscription !== null) {
                $this->saveEvent(
                    $billingEvent,
                    (string) $workspace->getKey(),
                    $eventId,
                    $eventType,
                    $createdAt,
                    'ignored',
                    'subscription_event_is_entitlement_authority',
                    $this->safeMetadata($object),
                );

                return true;
            }

            if ($subscription === null && $providerSubscriptionId === null && $current?->subscription instanceof ProductSubscription) {
                $subscription = $current->subscription;
            }

            if ($this->isStale($subscription, $createdAt, $eventId)) {
                $this->saveEvent($billingEvent, (string) $workspace->getKey(), $eventId, $eventType, $createdAt, 'ignored', 'stale_event', $this->safeMetadata($object));

                return true;
            }

            $projection = $this->projection($eventType, $object, $subscription);
            if ($projection === null) {
                $this->saveEvent($billingEvent, (string) $workspace->getKey(), $eventId, $eventType, $createdAt, 'needs_review', 'plan_or_subscription_unrecognized', $this->safeMetadata($object));

                return true;
            }

            if ($this->conflictsWithCurrent($current, $subscription, $projection['provider_subscription_id'], $projection['status'])) {
                $this->saveEvent($billingEvent, (string) $workspace->getKey(), $eventId, $eventType, $createdAt, 'needs_review', 'multiple_current_deployer_subscriptions', $this->safeMetadata($object));

                return true;
            }

            $customer = $this->resolveBillingCustomer($workspace, $projection['provider_customer_id']);
            if ($customer === false) {
                $this->saveEvent($billingEvent, (string) $workspace->getKey(), $eventId, $eventType, $createdAt, 'needs_review', 'billing_customer_mapping_conflict', $this->safeMetadata($object));

                return true;
            }

            $subscription = $this->persistSubscription($workspace, $subscription, $customer, $projection, $createdAt, $eventId, $eventType);
            $current = $this->updateCurrentAssignment($workspace, $current, $subscription, $projection, $createdAt, $eventId);
            $this->reconcileCurrentSubscriptionMap($sourceOrganizationId, $workspace, $current);
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
            'checkout.session.async_payment_succeeded',
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted',
            'invoice.paid',
            'invoice.payment_failed',
        ], true);
    }

    /** @param array<string, mixed> $object
     * @return array{0:?CoreWorkspace,1:?string,2:?string}
     */
    private function resolveWorkspace(array $object): array
    {
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
        $sourceOrganizationId = $this->stringValue($metadata['organization_id'] ?? null);
        $coreWorkspaceId = $this->stringValue($metadata['core_workspace_id'] ?? null);
        $reference = $this->stringValue($object['client_reference_id'] ?? null);
        if ($coreWorkspaceId === null && $reference !== null && ! ctype_digit($reference)) {
            $coreWorkspaceId = $reference;
        } elseif ($sourceOrganizationId === null && $reference !== null && ctype_digit($reference)) {
            $sourceOrganizationId = $reference;
        }

        if ($sourceOrganizationId !== null) {
            $mapping = LegacyIdentityMap::query()
                ->where('source_product', self::PRODUCT)
                ->where('source_entity', 'organization')
                ->where('source_id', $sourceOrganizationId)
                ->first();

            if ($mapping === null
                || $mapping->status !== 'reconciled'
                || $mapping->canonical_entity !== 'workspace'
                || ! is_string($mapping->canonical_id)
                || $mapping->canonical_id === '') {
                return [null, $sourceOrganizationId, 'workspace_mapping_not_reconciled'];
            }

            if ($coreWorkspaceId !== null && $coreWorkspaceId !== $mapping->canonical_id) {
                return [null, $sourceOrganizationId, 'workspace_mapping_conflict'];
            }

            $coreWorkspaceId = $mapping->canonical_id;
        }

        if ($coreWorkspaceId !== null) {
            $workspace = CoreWorkspace::query()->find($coreWorkspaceId);

            return $workspace === null
                ? [null, $sourceOrganizationId, 'workspace_missing']
                : [$workspace, $sourceOrganizationId, null];
        }

        $subscriptionId = $this->subscriptionId($object);
        if ($subscriptionId !== null) {
            $subscription = ProductSubscription::query()
                ->where('provider', 'stripe')
                ->where('provider_account_key', self::PROVIDER_ACCOUNT)
                ->where('provider_subscription_id', $subscriptionId)
                ->first();

            if ($subscription !== null) {
                $workspace = CoreWorkspace::query()->find($subscription->workspace_id);

                return [$workspace, null, $workspace === null ? 'workspace_missing' : null];
            }
        }

        $customerId = $this->referenceId($object['customer'] ?? null);
        if ($customerId !== null) {
            $customer = BillingCustomer::query()
                ->where('provider', 'stripe')
                ->where('provider_account_key', self::PROVIDER_ACCOUNT)
                ->where('provider_customer_id', $customerId)
                ->first();

            if ($customer?->workspace_id !== null) {
                $workspace = CoreWorkspace::query()->find($customer->workspace_id);

                return [$workspace, null, $workspace === null ? 'workspace_missing' : null];
            }
        }

        return [null, $sourceOrganizationId, 'workspace_mapping_missing'];
    }

    /** @param array<string, mixed> $object
     * @return array<string,mixed>|null
     */
    private function projection(string $eventType, array $object, ?ProductSubscription $subscription): ?array
    {
        $subscriptionId = $this->subscriptionId($object) ?? $subscription?->provider_subscription_id;
        $customerId = $this->referenceId($object['customer'] ?? null)
            ?? $subscription?->billingCustomer?->provider_customer_id;
        $reportedPriceId = $this->priceId($object);
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
        $interval = in_array($metadata['interval'] ?? null, ['monthly', 'yearly'], true)
            ? $metadata['interval']
            : null;
        $metadataPlan = is_string($metadata['plan'] ?? null) ? $metadata['plan'] : null;

        $isCheckoutEvent = in_array($eventType, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true);
        if ($isCheckoutEvent) {
            if (($object['mode'] ?? null) !== 'subscription'
                || $metadataPlan === null
                || $interval === null
                || $this->stripe->priceId($metadataPlan, $interval) === null) {
                return null;
            }

            $priceId = $reportedPriceId ?? $this->stripe->priceId($metadataPlan, $interval);
            $planKey = $this->stripe->planForPrice($priceId);
            if ($planKey !== $metadataPlan) {
                return null;
            }
        } else {
            $priceId = $reportedPriceId ?? $subscription?->provider_price_id;
            $planKey = $reportedPriceId !== null
                ? $this->stripe->planForPrice($reportedPriceId)
                : $subscription?->plan_key;
        }
        $planSnapshot = $planKey === null ? null : $this->snapshots->forPlan($planKey);

        if ($subscriptionId === null || $planKey === null || $planSnapshot === null) {
            return null;
        }

        $status = match ($eventType) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => 'incomplete',
            'customer.subscription.created', 'customer.subscription.updated' => $this->stripeStatus($object['status'] ?? null),
            'customer.subscription.deleted' => 'canceled',
            'invoice.paid' => 'active',
            'invoice.payment_failed' => 'past_due',
            default => null,
        };

        if ($status === null) {
            return null;
        }

        $items = data_get($object, 'items.data', []);
        $itemQuantities = is_array($items)
            ? array_map(static fn (mixed $item): int => is_array($item) && is_numeric($item['quantity'] ?? null) ? max(0, (int) $item['quantity']) : 0, $items)
            : [];
        $quantity = array_sum($itemQuantities);

        return [
            'provider_subscription_id' => $subscriptionId,
            'provider_customer_id' => $customerId,
            'provider_price_id' => $priceId ?? $subscription?->provider_price_id,
            'plan_key' => $planKey,
            'plan_snapshot' => $planSnapshot,
            'billing_interval' => $interval ?? $this->intervalForPrice($planKey, $priceId) ?? $subscription?->metadata['billing_interval'] ?? null,
            'status' => $status,
            'quantity' => max(1, $quantity ?: (int) ($object['quantity'] ?? $subscription?->quantity ?? 1)),
            'trial_ends_at' => $this->timestamp($object['trial_end'] ?? null) ?? $subscription?->trial_ends_at,
            'current_period_starts_at' => $this->timestamp($object['current_period_start'] ?? null) ?? $subscription?->current_period_starts_at,
            'current_period_ends_at' => $this->timestamp($object['current_period_end'] ?? null) ?? $subscription?->current_period_ends_at,
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
        if (! in_array($incomingStatus, ['active', 'trialing', 'past_due', 'incomplete', 'paused'], true)
            || ! $current?->subscription instanceof ProductSubscription) {
            return false;
        }

        $existing = $current->subscription;
        if ($incoming !== null && (string) $incoming->getKey() === (string) $existing->getKey()) {
            return false;
        }

        return ! $this->isFreeFallback($existing)
            && ! in_array($existing->status, ['canceled', 'incomplete_expired'], true)
            && $existing->provider_subscription_id !== $incomingSubscriptionId;
    }

    private function isFreeFallback(ProductSubscription $subscription): bool
    {
        return $subscription->plan_key === 'free'
            && in_array($subscription->provider, ['deployer_legacy', 'legacy_access'], true);
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
                'metadata' => ['source' => 'deployer_stripe_webhook'],
            ]);
        }

        if ($customer->workspace_id === null) {
            $otherWorkspaceSubscription = ProductSubscription::query()
                ->where('billing_customer_id', $customer->getKey())
                ->where(fn ($query) => $query
                    ->where('workspace_id', '!=', $workspace->getKey())
                    ->orWhere('product', '!=', self::PRODUCT))
                ->exists();

            if ($otherWorkspaceSubscription) {
                return false;
            }

            $customer->forceFill(['workspace_id' => $workspace->getKey()])->save();
        }

        return $customer;
    }

    /** @param array<string,mixed> $projection */
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
                'product' => self::PRODUCT,
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
        if ($projection['billing_interval'] !== null) {
            $metadata['billing_interval'] = $projection['billing_interval'];
        }
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

    /** @param array<string,mixed> $projection */
    private function updateCurrentAssignment(
        CoreWorkspace $workspace,
        ?CurrentProductSubscription $current,
        ProductSubscription $subscription,
        array $projection,
        ?int $createdAt,
        string $eventId,
    ): CurrentProductSubscription {
        $incomingIsCurrent = in_array($projection['status'], ['active', 'trialing', 'past_due', 'incomplete', 'paused'], true);
        if ($current === null) {
            $subscription = $incomingIsCurrent ? $subscription : $this->freeSubscription($workspace, $createdAt, $eventId);

            return CurrentProductSubscription::query()->create([
                'workspace_id' => $workspace->getKey(),
                'product' => self::PRODUCT,
                'product_subscription_id' => $subscription->getKey(),
            ]);
        }

        $existing = $current->subscription;
        if ((string) $current->product_subscription_id === (string) $subscription->getKey()) {
            if (! $incomingIsCurrent) {
                $free = $this->freeSubscription($workspace, $createdAt, $eventId);
                $current->forceFill(['product_subscription_id' => $free->getKey()])->save();
            }

            return $current;
        }

        if ($this->isFreeFallback($existing) && $incomingIsCurrent) {
            $current->forceFill(['product_subscription_id' => $subscription->getKey()])->save();
        } elseif ($existing instanceof ProductSubscription
            && in_array($existing->status, ['canceled', 'unpaid', 'incomplete_expired'], true)
            && $incomingIsCurrent) {
            $current->forceFill(['product_subscription_id' => $subscription->getKey()])->save();
        }

        return $current;
    }

    private function freeSubscription(CoreWorkspace $workspace, ?int $createdAt, string $eventId): ProductSubscription
    {
        $snapshot = $this->snapshots->forPlan('free');
        if ($snapshot === null) {
            throw new InvalidArgumentException('The Deployer free plan snapshot is unavailable.');
        }

        $subscription = ProductSubscription::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('product', self::PRODUCT)
            ->where('provider', 'deployer_legacy')
            ->where('provider_account_key', self::PROVIDER_ACCOUNT)
            ->whereNull('provider_subscription_id')
            ->where('plan_key', 'free')
            ->lockForUpdate()
            ->first();

        if ($subscription === null) {
            return ProductSubscription::query()->create([
                'workspace_id' => $workspace->getKey(),
                'billing_customer_id' => null,
                'product' => self::PRODUCT,
                'provider' => 'deployer_legacy',
                'provider_account_key' => self::PROVIDER_ACCOUNT,
                'provider_subscription_id' => null,
                'provider_price_id' => null,
                'plan_key' => 'free',
                'status' => 'active',
                'quantity' => 1,
                'metadata' => [
                    'entitlement_snapshot' => true,
                    'plan_snapshot' => $snapshot,
                    'billing_state' => [
                        'source' => 'deployer_free_fallback',
                        'event_position' => ['created_at' => $createdAt, 'event_id' => $eventId],
                    ],
                ],
            ]);
        }

        $metadata = is_array($subscription->metadata) ? $subscription->metadata : [];
        $metadata['plan_snapshot'] = $snapshot;
        $metadata['billing_state'] = array_merge(
            is_array($metadata['billing_state'] ?? null) ? $metadata['billing_state'] : [],
            ['source' => 'deployer_free_fallback', 'event_position' => ['created_at' => $createdAt, 'event_id' => $eventId]],
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

        return $subscription;
    }

    private function reconcileCurrentSubscriptionMap(?string $sourceOrganizationId, CoreWorkspace $workspace, CurrentProductSubscription $current): void
    {
        if ($sourceOrganizationId === null) {
            return;
        }

        $workspaceMapping = LegacyIdentityMap::query()
            ->where('source_product', self::PRODUCT)
            ->where('source_entity', 'organization')
            ->where('source_id', $sourceOrganizationId)
            ->first();

        if ($workspaceMapping?->status !== 'reconciled'
            || $workspaceMapping->canonical_entity !== 'workspace'
            || (string) $workspaceMapping->canonical_id !== (string) $workspace->getKey()) {
            return;
        }

        $mapping = LegacyIdentityMap::query()
            ->where('source_product', self::PRODUCT)
            ->where('source_entity', 'current_subscription')
            ->where('source_id', $sourceOrganizationId)
            ->lockForUpdate()
            ->first();
        $attributes = [
            'canonical_entity' => 'current_product_subscription',
            'canonical_id' => (string) $current->getKey(),
            'status' => 'reconciled',
            'batch_key' => 'deployer-live-billing-webhook-v1',
            'reconciliation_notes' => null,
            'metadata' => [
                'product_subscription_id' => (string) $current->product_subscription_id,
                'workspace_id' => (string) $workspace->getKey(),
                'updated_by' => 'deployer_stripe_webhook',
            ],
            'reconciled_at' => now('UTC'),
        ];

        if ($mapping === null) {
            LegacyIdentityMap::query()->create([
                'source_product' => self::PRODUCT,
                'source_entity' => 'current_subscription',
                'source_id' => $sourceOrganizationId,
                ...$attributes,
            ]);

            return;
        }

        if ($mapping->status === 'reconciled'
            && $mapping->canonical_entity === 'current_product_subscription'
            && (string) $mapping->canonical_id === (string) $current->getKey()) {
            $mapping->fill($attributes)->save();
        }
    }

    private function isStale(?ProductSubscription $subscription, ?int $createdAt, string $eventId): bool
    {
        if ($subscription === null || $createdAt === null) {
            return false;
        }

        $metadata = $subscription->metadata;
        $billingState = is_array($metadata) && is_array($metadata['billing_state'] ?? null) ? $metadata['billing_state'] : [];
        $position = $billingState['event_position'] ?? $billingState['source_billing_event_position'] ?? [];
        $previousCreatedAt = is_array($position) && is_numeric($position['created_at'] ?? null) ? (int) $position['created_at'] : null;
        $previousEventId = is_array($position) && is_string($position['event_id'] ?? null) ? $position['event_id'] : null;

        return $previousCreatedAt !== null
            && ($createdAt < $previousCreatedAt
                || ($createdAt === $previousCreatedAt && $previousEventId !== null && strcmp($eventId, $previousEventId) <= 0));
    }

    /** @return array<string,mixed> */
    private function safeMetadata(array $object): array
    {
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];

        return array_filter([
            'source_organization_id' => $metadata['organization_id'] ?? $object['client_reference_id'] ?? null,
            'canonical_workspace_id' => $metadata['core_workspace_id'] ?? null,
            'provider_customer_id' => $this->referenceId($object['customer'] ?? null),
            'provider_subscription_id' => $this->subscriptionId($object),
            'provider_price_id' => $this->priceId($object),
            'plan_key' => $metadata['plan'] ?? $this->stripe->planForPrice($this->priceId($object)),
            'provider_status' => $this->stringValue($object['status'] ?? $object['payment_status'] ?? null),
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
            'product' => self::PRODUCT,
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

    /** @param array<string,mixed> $projection */
    private function cancelAt(array $projection): ?CarbonImmutable
    {
        if ($projection['cancel_at'] instanceof CarbonImmutable) {
            return $projection['cancel_at'];
        }

        return $projection['cancel_at_period_end'] ? $projection['current_period_ends_at'] : null;
    }

    private function subscriptionId(array $object): ?string
    {
        $id = $this->referenceId($object['subscription'] ?? null)
            ?? $this->referenceId(data_get($object, 'parent.subscription_details.subscription'));
        if ($id === null && ($object['object'] ?? null) === 'subscription') {
            $id = $this->stringValue($object['id'] ?? null);
        }

        return $id;
    }

    private function priceId(array $object): ?string
    {
        $items = data_get($object, 'items.data', []);
        if (is_array($items)) {
            foreach ($items as $item) {
                $candidate = is_array($item) ? $this->referenceId($item['price'] ?? null) : null;
                if ($candidate !== null && $this->stripe->planForPrice($candidate) !== null) {
                    return $candidate;
                }
            }
        }

        $lines = data_get($object, 'lines.data', []);
        if (is_array($lines)) {
            foreach ($lines as $line) {
                if (! is_array($line)) {
                    continue;
                }
                $candidate = $this->referenceId($line['price'] ?? null)
                    ?? $this->referenceId(data_get($line, 'pricing.price_details.price'));
                if ($candidate !== null && $this->stripe->planForPrice($candidate) !== null) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function intervalForPrice(string $plan, ?string $priceId): ?string
    {
        if ($priceId === null) {
            return null;
        }

        foreach (['monthly', 'yearly'] as $interval) {
            if ($this->stripe->priceId($plan, $interval) === $priceId) {
                return $interval;
            }
        }

        return null;
    }

    private function referenceId(mixed $value): ?string
    {
        return $this->stringValue(is_array($value) ? ($value['id'] ?? null) : $value);
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
        return is_string($value) || is_int($value) ? (trim((string) $value) !== '' ? trim((string) $value) : null) : null;
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        return is_numeric($value) ? CarbonImmutable::createFromTimestampUTC((int) $value) : null;
    }
}
