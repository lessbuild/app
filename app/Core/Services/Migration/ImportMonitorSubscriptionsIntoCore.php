<?php

namespace App\Core\Services\Migration;

use App\Core\Models\BillingCustomer;
use App\Core\Models\CurrentProductSubscription;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\ProductBillingEvent;
use App\Core\Models\ProductSubscription;
use App\Core\Models\Workspace;
use App\Modules\Monitor\Services\MonitorPlanSnapshot;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class ImportMonitorSubscriptionsIntoCore
{
    private const PRODUCT = 'monitor';

    private const PROVIDER_ACCOUNT = 'monitor';

    private const BATCH_KEY = 'monitor-subscription-import-v1';

    /**
     * Preview or import Monitor's current workspace plans, Stripe records, and webhook history.
     * Product and provider account keys keep Monitor billing isolated from the other modules.
     *
     * @return array{workspaces_seen:int,subscriptions_ready:int,subscriptions_imported:int,subscriptions_already_mapped:int,subscriptions_blocked:int,stripe_subscriptions_imported:int,billing_customers_imported:int,billing_events_seen:int,billing_events_ready:int,billing_events_imported:int,billing_events_already_mapped:int,billing_events_blocked:int,review_records_created:int}
     */
    public function run(bool $apply = false): array
    {
        foreach (['workspaces', 'billing_events'] as $table) {
            if (! Schema::connection('monitor')->hasTable($table)) {
                throw new RuntimeException("The Monitor {$table} table is unavailable on the monitor connection.");
            }
        }

        foreach ([
            'legacy_identity_maps', 'workspaces', 'billing_customers', 'product_subscriptions',
            'current_product_subscriptions', 'product_billing_events',
        ] as $table) {
            if (! Schema::connection('core')->hasTable($table)) {
                throw new RuntimeException('Run the Core platform and product billing migrations before importing Monitor subscriptions.');
            }
        }

        $workspaces = DB::connection('monitor')->table('workspaces')->orderBy('id')->get();
        $events = DB::connection('monitor')->table('billing_events')->orderBy('id')->get();
        $workspaceMaps = $this->mappingsFor('workspace');
        $currentMaps = $this->mappingsFor('current_subscription');
        $subscriptionMaps = $this->mappingsFor('subscription');
        $customerMaps = $this->mappingsFor('billing_customer');
        $eventMaps = $this->mappingsFor('billing_event');

        $report = [
            'workspaces_seen' => $workspaces->count(),
            'subscriptions_ready' => 0,
            'subscriptions_imported' => 0,
            'subscriptions_already_mapped' => 0,
            'subscriptions_blocked' => 0,
            'stripe_subscriptions_imported' => 0,
            'billing_customers_imported' => 0,
            'billing_events_seen' => $events->count(),
            'billing_events_ready' => 0,
            'billing_events_imported' => 0,
            'billing_events_already_mapped' => 0,
            'billing_events_blocked' => 0,
            'review_records_created' => 0,
        ];

        foreach ($workspaces as $source) {
            $sourceId = (string) $source->id;
            $currentMap = $currentMaps->get($sourceId);

            if ($currentMap?->status === 'reconciled') {
                $assignment = CurrentProductSubscription::query()->find($currentMap->canonical_id);
                $workspaceMap = $workspaceMaps->get($sourceId);
                $expectedWorkspaceId = $workspaceMap?->status === 'reconciled'
                    && $workspaceMap->canonical_entity === 'workspace'
                    ? (string) $workspaceMap->canonical_id
                    : null;
                $subscriptionExists = $assignment !== null && ProductSubscription::query()
                    ->whereKey($assignment->product_subscription_id)
                    ->where('workspace_id', $expectedWorkspaceId)
                    ->where('product', self::PRODUCT)
                    ->exists();

                if ($currentMap->canonical_entity === 'current_product_subscription'
                    && $assignment !== null
                    && $expectedWorkspaceId !== null
                    && (string) $assignment->workspace_id === $expectedWorkspaceId
                    && $assignment->product === self::PRODUCT
                    && $subscriptionExists) {
                    $report['subscriptions_already_mapped']++;
                } else {
                    $report['subscriptions_blocked']++;
                }

                continue;
            }

            if ($currentMap !== null && ($currentMap->status !== 'needs_review' || $currentMap->canonical_id !== null)) {
                $report['subscriptions_blocked']++;

                continue;
            }

            $workspaceMap = $workspaceMaps->get($sourceId);
            $coreWorkspace = $workspaceMap?->status === 'reconciled'
                && $workspaceMap->canonical_entity === 'workspace'
                ? Workspace::query()->find($workspaceMap->canonical_id)
                : null;
            $decision = $this->decisionFor($source);
            $reasons = $decision['reason_codes'];

            if ($coreWorkspace === null) {
                $reasons[] = 'monitor_workspace_not_reconciled';
            } elseif (CurrentProductSubscription::query()
                ->where('workspace_id', $coreWorkspace->getKey())
                ->where('product', self::PRODUCT)
                ->exists()) {
                $reasons[] = 'core_monitor_subscription_slot_already_occupied';
            }

            $customerId = $this->stringValue($source->stripe_customer_id ?? null);
            $customerMap = $customerMaps->get($customerId ?? '');
            if ($customerId !== null && $customerMap?->status === 'reconciled') {
                $mappedCustomer = $customerMap->canonical_entity === 'billing_customer'
                    ? BillingCustomer::query()->find($customerMap->canonical_id)
                    : null;

                if ($mappedCustomer === null
                    || $mappedCustomer->provider !== 'stripe'
                    || $mappedCustomer->provider_account_key !== self::PROVIDER_ACCOUNT
                    || $mappedCustomer->provider_customer_id !== $customerId
                    || (string) $mappedCustomer->workspace_id !== (string) ($coreWorkspace?->getKey() ?? '')) {
                    $reasons[] = 'mapped_billing_customer_has_wrong_workspace_or_provider';
                }
            } elseif ($customerId !== null) {
                if ($customerMap !== null && ($customerMap->status !== 'needs_review' || $customerMap->canonical_id !== null)) {
                    $reasons[] = 'existing_billing_customer_mapping_requires_review';
                }

                if (BillingCustomer::query()
                    ->where('provider', 'stripe')
                    ->where('provider_account_key', self::PROVIDER_ACCOUNT)
                    ->where('provider_customer_id', $customerId)
                    ->exists()) {
                    $reasons[] = 'stripe_customer_id_already_exists_in_core';
                }
            }

            $stripeSubscriptionId = $decision['stripe_subscription_id'];
            $stripeSubscriptionMap = $subscriptionMaps->get($stripeSubscriptionId ?? '');
            if ($stripeSubscriptionId !== null && $stripeSubscriptionMap?->status === 'reconciled') {
                $mappedSubscription = $stripeSubscriptionMap->canonical_entity === 'product_subscription'
                    ? ProductSubscription::query()->find($stripeSubscriptionMap->canonical_id)
                    : null;

                if ($mappedSubscription === null
                    || $mappedSubscription->workspace_id !== $coreWorkspace?->getKey()
                    || $mappedSubscription->product !== self::PRODUCT
                    || $mappedSubscription->provider !== 'stripe'
                    || $mappedSubscription->provider_account_key !== self::PROVIDER_ACCOUNT
                    || $mappedSubscription->provider_subscription_id !== $stripeSubscriptionId) {
                    $reasons[] = 'mapped_stripe_subscription_has_wrong_workspace_or_product';
                }
            } elseif ($stripeSubscriptionId !== null) {
                if ($stripeSubscriptionMap !== null
                    && ($stripeSubscriptionMap->status !== 'needs_review' || $stripeSubscriptionMap->canonical_id !== null)) {
                    $reasons[] = 'existing_stripe_subscription_mapping_requires_review';
                }

                if (ProductSubscription::query()
                    ->where('provider', 'stripe')
                    ->where('provider_account_key', self::PROVIDER_ACCOUNT)
                    ->where('provider_subscription_id', $stripeSubscriptionId)
                    ->exists()) {
                    $reasons[] = 'stripe_subscription_id_already_exists_in_core';
                }
            }

            $reasons = array_values(array_unique($reasons));

            if ($reasons !== []) {
                $report['subscriptions_blocked']++;
                if ($apply && $this->recordReview('current_subscription', $sourceId, $reasons)) {
                    $report['review_records_created']++;
                }

                continue;
            }

            $report['subscriptions_ready']++;

            if ($apply && $coreWorkspace !== null && $this->importWorkspaceSubscription(
                $source,
                $coreWorkspace,
                $decision,
                $customerMaps->get($customerId ?? ''),
                $subscriptionMaps->get($stripeSubscriptionId ?? ''),
                $report,
            )) {
                $report['subscriptions_imported']++;
            }
        }

        foreach ($events as $source) {
            $sourceId = (string) $source->stripe_event_id;
            $existingMap = $eventMaps->get($sourceId);

            if ($existingMap?->status === 'reconciled') {
                $mappedEvent = $existingMap->canonical_entity === 'product_billing_event'
                    ? ProductBillingEvent::query()->find($existingMap->canonical_id)
                    : null;

                if ($mappedEvent !== null
                    && $mappedEvent->provider === 'stripe'
                    && $mappedEvent->provider_account_key === self::PROVIDER_ACCOUNT
                    && $mappedEvent->provider_event_id === $sourceId
                    && $mappedEvent->product === self::PRODUCT) {
                    $report['billing_events_already_mapped']++;
                } else {
                    $report['billing_events_blocked']++;
                }

                continue;
            }

            if ($existingMap !== null && ($existingMap->status !== 'needs_review' || $existingMap->canonical_id !== null)) {
                $report['billing_events_blocked']++;

                continue;
            }

            if (ProductBillingEvent::query()
                ->where('provider', 'stripe')
                ->where('provider_account_key', self::PROVIDER_ACCOUNT)
                ->where('provider_event_id', $sourceId)
                ->exists()) {
                $report['billing_events_blocked']++;
                if ($apply && $this->recordReview('billing_event', $sourceId, ['stripe_event_id_already_exists_in_core'])) {
                    $report['review_records_created']++;
                }

                continue;
            }

            $report['billing_events_ready']++;

            if ($apply) {
                if ($this->importBillingEvent($source, $existingMap, $report)) {
                    $report['billing_events_imported']++;
                } else {
                    $report['billing_events_blocked']++;
                }
            }
        }

        return $report;
    }

    /** @return array{plan_key:string,plan_snapshot:array<string,mixed>,billing_status:string,stripe_subscription_id:?string,stripe_customer_id:?string,stripe_price_id:?string,billing_period_ends_at:mixed,cancel_at_period_end:bool,stripe_subscription_is_current:bool,reason_codes:list<string>,billing_metadata:array<string,mixed>} */
    private function decisionFor(object $source): array
    {
        $plans = config('monitor.beacon.plans', []);
        $planKey = trim((string) ($source->plan ?? ''));
        $status = trim((string) ($source->billing_status ?? 'inactive')) ?: 'inactive';
        $stripeSubscriptionId = $this->stringValue($source->stripe_subscription_id ?? null);
        $stripeCustomerId = $this->stringValue($source->stripe_customer_id ?? null);
        $stripePriceId = $this->stringValue($source->stripe_price_id ?? null);
        $reasonCodes = [];

        if (! is_array($plans) || ! isset($plans[$planKey]) || ! is_array($plans[$planKey])) {
            $reasonCodes[] = 'unknown_monitor_plan';
            $planSnapshot = [];
        } else {
            $planSnapshot = $this->normalizedPlanSnapshot($plans[$planKey]);
        }

        $stripeSubscriptionIsCurrent = $stripeSubscriptionId !== null
            && in_array($status, ['active', 'trialing', 'past_due', 'paused'], true)
            && $planKey !== 'free';

        if ($stripeSubscriptionId !== null
            && in_array($status, ['active', 'trialing', 'past_due'], true)
            && $planKey === 'free') {
            $reasonCodes[] = 'active_stripe_subscription_has_free_monitor_plan';
        }

        $billingMetadata = [
            'source_plan' => $planKey,
            'source_billing_status' => $status,
            'source_billing_updated_at' => $source->billing_updated_at ?? null,
            'source_billing_period_ends_at' => $source->billing_period_ends_at ?? null,
            'source_cancel_at_period_end' => (bool) ($source->billing_cancel_at_period_end ?? false),
            'source_billing_event_position' => [
                'created_at' => isset($source->billing_event_created_at) ? (int) $source->billing_event_created_at : null,
                'event_id' => $source->billing_event_id ?? null,
            ],
            'plan_snapshot' => is_array($plans[$planKey] ?? null) ? $plans[$planKey] : [],
        ];

        if (filled($source->stripe_checkout_session_id ?? null)) {
            $billingMetadata['pending_checkout'] = [
                'session_id' => (string) $source->stripe_checkout_session_id,
                'plan_key' => $source->billing_checkout_plan ?? null,
                'started_at' => $source->billing_checkout_started_at ?? null,
                'checkout_url_present' => filled($source->stripe_checkout_url ?? null),
                'checkout_url_copied' => false,
            ];
        }

        return [
            'plan_key' => $planKey,
            'plan_snapshot' => $planSnapshot,
            'billing_status' => $status,
            'stripe_subscription_id' => $stripeSubscriptionId,
            'stripe_customer_id' => $stripeCustomerId,
            'stripe_price_id' => $stripePriceId,
            'billing_period_ends_at' => $source->billing_period_ends_at ?? null,
            'cancel_at_period_end' => (bool) ($source->billing_cancel_at_period_end ?? false),
            'stripe_subscription_is_current' => $stripeSubscriptionIsCurrent,
            'reason_codes' => $reasonCodes,
            'billing_metadata' => $billingMetadata,
        ];
    }

    /**
     * Keep Monitor's catalog fields for display and migration review while adding
     * a stable, product-neutral entitlement and quota contract for Core.
     *
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function normalizedPlanSnapshot(array $source): array
    {
        return app(MonitorPlanSnapshot::class)->normalize($source);
    }

    /**
     * @param  array<string, mixed>  $decision
     * @param  array<string, int>  $report
     */
    private function importWorkspaceSubscription(
        object $source,
        Workspace $workspace,
        array $decision,
        ?LegacyIdentityMap $customerMap,
        ?LegacyIdentityMap $stripeSubscriptionMap,
        array &$report,
    ): bool {
        $sourceWorkspaceId = (string) $source->id;

        return DB::connection('core')->transaction(function () use (
            $source,
            $workspace,
            $sourceWorkspaceId,
            $decision,
            $customerMap,
            $stripeSubscriptionMap,
            &$report,
        ): bool {
            $lockedCurrentMap = $this->sourceMapping('current_subscription', $sourceWorkspaceId, lock: true);
            if ($lockedCurrentMap !== null && ($lockedCurrentMap->status !== 'needs_review' || $lockedCurrentMap->canonical_id !== null)) {
                return false;
            }

            if (CurrentProductSubscription::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('product', self::PRODUCT)
                ->exists()) {
                if ($this->recordReviewInsideTransaction('current_subscription', $sourceWorkspaceId, ['core_monitor_subscription_slot_already_occupied'])) {
                    $report['review_records_created']++;
                }

                return false;
            }

            $billingCustomerId = $this->importBillingCustomer($source, $workspace, $customerMap, $report);
            if ($billingCustomerId === false) {
                return false;
            }

            $currentSubscription = null;
            $stripeSubscriptionId = $decision['stripe_subscription_id'];

            if ($stripeSubscriptionId !== null) {
                $stripeSubscription = $this->importStripeSubscription(
                    $source,
                    $workspace,
                    $billingCustomerId,
                    $decision,
                    $stripeSubscriptionMap,
                    $report,
                );

                if ($stripeSubscription === false) {
                    return false;
                }

                if ($decision['stripe_subscription_is_current']) {
                    $currentSubscription = $stripeSubscription;
                }
            }

            if ($currentSubscription === null) {
                $currentSubscription = ProductSubscription::query()->create([
                    'workspace_id' => $workspace->getKey(),
                    'billing_customer_id' => $billingCustomerId,
                    'product' => self::PRODUCT,
                    'provider' => 'monitor_legacy',
                    'provider_account_key' => self::PROVIDER_ACCOUNT,
                    'provider_subscription_id' => null,
                    'provider_price_id' => null,
                    'plan_key' => $decision['plan_key'],
                    'status' => 'active',
                    'quantity' => 1,
                    'current_period_starts_at' => null,
                    'current_period_ends_at' => null,
                    'cancel_at' => null,
                    'canceled_at' => null,
                    'metadata' => [
                        'migration_source' => 'monitor',
                        'source_workspace_id' => $sourceWorkspaceId,
                        'entitlement_snapshot' => true,
                        'plan_snapshot' => $decision['plan_snapshot'],
                        'billing_state' => $decision['billing_metadata'],
                    ],
                ]);
                $this->preserveTimestamps($currentSubscription, $source, $source->billing_updated_at ?? null);
            } else {
                $currentSubscription->forceFill([
                    'metadata' => array_merge($currentSubscription->metadata ?? [], [
                        'billing_state' => $decision['billing_metadata'],
                    ]),
                ])->save();
            }

            $currentAssignment = CurrentProductSubscription::query()->create([
                'workspace_id' => $workspace->getKey(),
                'product' => self::PRODUCT,
                'product_subscription_id' => $currentSubscription->getKey(),
            ]);

            $mapAttributes = [
                'canonical_entity' => 'current_product_subscription',
                'canonical_id' => $currentAssignment->getKey(),
                'status' => 'reconciled',
                'batch_key' => self::BATCH_KEY,
                'reconciliation_notes' => null,
                'metadata' => array_merge($this->reconciledMetadata($lockedCurrentMap), [
                    'product_subscription_id' => (string) $currentSubscription->getKey(),
                    'plan_key' => $decision['plan_key'],
                    'provider' => $currentSubscription->provider,
                    'provider_subscription_id' => $currentSubscription->provider_subscription_id,
                ]),
                'imported_at' => now(),
                'reconciled_at' => now(),
            ];

            if ($lockedCurrentMap !== null) {
                $lockedCurrentMap->fill($mapAttributes)->save();
            } else {
                LegacyIdentityMap::query()->create([
                    'source_product' => self::PRODUCT,
                    'source_entity' => 'current_subscription',
                    'source_id' => $sourceWorkspaceId,
                    ...$mapAttributes,
                ]);
            }

            return true;
        });
    }

    /** @param array<string, int> $report */
    private function importBillingCustomer(object $source, Workspace $workspace, ?LegacyIdentityMap $existingMap, array &$report): string|false|null
    {
        $sourceId = $this->stringValue($source->stripe_customer_id ?? null);
        if ($sourceId === null) {
            return null;
        }

        $mapping = $this->sourceMapping('billing_customer', $sourceId, lock: true) ?? $existingMap;
        if ($mapping !== null) {
            if ($mapping->status === 'reconciled' && $mapping->canonical_entity === 'billing_customer') {
                $customer = BillingCustomer::query()->find($mapping->canonical_id);

                if ($customer !== null
                    && $customer->provider === 'stripe'
                    && $customer->provider_account_key === self::PROVIDER_ACCOUNT
                    && $customer->provider_customer_id === $sourceId
                    && (string) $customer->workspace_id === (string) $workspace->getKey()) {
                    return (string) $customer->getKey();
                }

                if ($this->recordReviewInsideTransaction('billing_customer', $sourceId, ['mapped_billing_customer_has_wrong_workspace_or_provider'])) {
                    $report['review_records_created']++;
                }

                return false;
            }

            if ($mapping->status !== 'needs_review' || $mapping->canonical_id !== null) {
                if ($this->recordReviewInsideTransaction('billing_customer', $sourceId, ['existing_billing_customer_mapping_requires_review'])) {
                    $report['review_records_created']++;
                }

                return false;
            }
        }

        $collision = BillingCustomer::query()
            ->where('provider', 'stripe')
            ->where('provider_account_key', self::PROVIDER_ACCOUNT)
            ->where('provider_customer_id', $sourceId)
            ->first();

        if ($collision !== null) {
            if ($this->recordReviewInsideTransaction('billing_customer', $sourceId, ['stripe_customer_id_already_exists_in_core'])) {
                $report['review_records_created']++;
            }

            return false;
        }

        $now = now();
        $customer = BillingCustomer::query()->create([
            'workspace_id' => $workspace->getKey(),
            'provider' => 'stripe',
            'provider_account_key' => self::PROVIDER_ACCOUNT,
            'provider_customer_id' => $sourceId,
            'status' => 'active',
            'metadata' => [
                'migration_source' => 'monitor',
                'source_workspace_id' => (string) $source->id,
            ],
        ]);
        $this->preserveTimestamps($customer, $source, $source->billing_updated_at ?? null);
        $this->reconcileMap('billing_customer', $sourceId, 'billing_customer', (string) $customer->getKey(), $mapping, [
            'source_workspace_id' => (string) $source->id,
        ], $now);
        $report['billing_customers_imported']++;

        return (string) $customer->getKey();
    }

    /** @param array<string, mixed> $decision
     * @param  array<string, int>  $report
     */
    private function importStripeSubscription(
        object $source,
        Workspace $workspace,
        string|false|null $billingCustomerId,
        array $decision,
        ?LegacyIdentityMap $existingMap,
        array &$report,
    ): ProductSubscription|false {
        $sourceId = $decision['stripe_subscription_id'];
        $mapping = $this->sourceMapping('subscription', $sourceId, lock: true) ?? $existingMap;

        if ($mapping !== null) {
            if ($mapping->status === 'reconciled' && $mapping->canonical_entity === 'product_subscription') {
                $subscription = ProductSubscription::query()->find($mapping->canonical_id);
                if ($subscription !== null
                    && (string) $subscription->workspace_id === (string) $workspace->getKey()
                    && $subscription->product === self::PRODUCT
                    && $subscription->provider === 'stripe'
                    && $subscription->provider_account_key === self::PROVIDER_ACCOUNT
                    && $subscription->provider_subscription_id === $sourceId) {
                    return $subscription;
                }

                if ($this->recordReviewInsideTransaction('subscription', $sourceId, ['mapped_stripe_subscription_has_wrong_workspace_or_product'])) {
                    $report['review_records_created']++;
                }

                return false;
            }

            if ($mapping->status !== 'needs_review' || $mapping->canonical_id !== null) {
                if ($this->recordReviewInsideTransaction('subscription', $sourceId, ['existing_stripe_subscription_mapping_requires_review'])) {
                    $report['review_records_created']++;
                }

                return false;
            }
        }

        if (ProductSubscription::query()
            ->where('provider', 'stripe')
            ->where('provider_account_key', self::PROVIDER_ACCOUNT)
            ->where('provider_subscription_id', $sourceId)
            ->exists()) {
            if ($this->recordReviewInsideTransaction('subscription', $sourceId, ['stripe_subscription_id_already_exists_in_core'])) {
                $report['review_records_created']++;
            }

            return false;
        }

        $subscriptionPlanKey = $decision['stripe_subscription_is_current']
            ? $decision['plan_key']
            : ($this->planKeyForPrice($decision['stripe_price_id']) ?? ($decision['plan_key'] === 'free' ? null : $decision['plan_key']));
        $subscription = ProductSubscription::query()->create([
            'workspace_id' => $workspace->getKey(),
            'billing_customer_id' => $billingCustomerId,
            'product' => self::PRODUCT,
            'provider' => 'stripe',
            'provider_account_key' => self::PROVIDER_ACCOUNT,
            'provider_subscription_id' => $sourceId,
            'provider_price_id' => $decision['stripe_price_id'],
            'plan_key' => $subscriptionPlanKey,
            'status' => $decision['billing_status'],
            'quantity' => 1,
            'current_period_starts_at' => null,
            'current_period_ends_at' => $decision['billing_period_ends_at'],
            'cancel_at' => $decision['cancel_at_period_end'] ? $decision['billing_period_ends_at'] : null,
            'canceled_at' => $decision['billing_status'] === 'canceled'
                ? ($source->billing_updated_at ?? null)
                : null,
            'metadata' => [
                'migration_source' => 'monitor',
                'source_workspace_id' => (string) $source->id,
                'current_in_source' => $decision['stripe_subscription_is_current'],
                'plan_snapshot' => $decision['plan_snapshot'],
                'billing_state' => $decision['billing_metadata'],
            ],
        ]);
        $this->preserveTimestamps($subscription, $source, $source->billing_updated_at ?? null);
        $this->reconcileMap('subscription', $sourceId, 'product_subscription', (string) $subscription->getKey(), $mapping, [
            'source_workspace_id' => (string) $source->id,
        ], now());
        $report['stripe_subscriptions_imported']++;

        return $subscription;
    }

    /** @param array<string, int> $report */
    private function importBillingEvent(object $source, ?LegacyIdentityMap $existingMap, array &$report): bool
    {
        $sourceId = (string) $source->stripe_event_id;

        return DB::connection('core')->transaction(function () use ($source, $sourceId, $existingMap, &$report): bool {
            $mapping = $this->sourceMapping('billing_event', $sourceId, lock: true);
            if ($mapping !== null && ($mapping->status !== 'needs_review' || $mapping->canonical_id !== null)) {
                return false;
            }

            if (ProductBillingEvent::query()
                ->where('provider', 'stripe')
                ->where('provider_account_key', self::PROVIDER_ACCOUNT)
                ->where('provider_event_id', $sourceId)
                ->exists()) {
                if ($this->recordReviewInsideTransaction('billing_event', $sourceId, ['stripe_event_id_already_exists_in_core'])) {
                    $report['review_records_created']++;
                }

                return false;
            }

            $sourceWorkspaceId = $source->workspace_id === null ? null : (string) $source->workspace_id;
            $workspaceMap = $sourceWorkspaceId === null ? null : $this->sourceMapping('workspace', $sourceWorkspaceId);
            $canonicalWorkspaceId = $workspaceMap?->status === 'reconciled'
                && $workspaceMap->canonical_entity === 'workspace'
                ? (string) $workspaceMap->canonical_id
                : null;
            $event = ProductBillingEvent::query()->create([
                'workspace_id' => $canonicalWorkspaceId,
                'product' => self::PRODUCT,
                'provider' => 'stripe',
                'provider_account_key' => self::PROVIDER_ACCOUNT,
                'provider_event_id' => $sourceId,
                'event_type' => (string) $source->event_type,
                'provider_created_at' => isset($source->stripe_created_at) ? (int) $source->stripe_created_at : null,
                'processing_status' => (string) ($source->processing_status ?? 'applied'),
                'ignored_reason' => $source->ignored_reason ?? null,
                'processed_at' => $source->processed_at ?? null,
                'metadata' => [
                    'migration_source' => 'monitor',
                    'source_workspace_id' => $sourceWorkspaceId,
                    'workspace_mapping_status' => $workspaceMap?->status ?? ($sourceWorkspaceId === null ? 'unassigned' : 'unmapped'),
                ],
            ]);
            $this->preserveTimestamps($event, $source);
            $this->reconcileMap('billing_event', $sourceId, 'product_billing_event', (string) $event->getKey(), $mapping ?? $existingMap, [
                'source_workspace_id' => $sourceWorkspaceId,
                'unmapped_workspace_event' => $sourceWorkspaceId !== null && $canonicalWorkspaceId === null,
            ], now());

            return true;
        });
    }

    /** @return Collection<string, LegacyIdentityMap> */
    private function mappingsFor(string $entity): Collection
    {
        return LegacyIdentityMap::query()
            ->where('source_product', self::PRODUCT)
            ->where('source_entity', $entity)
            ->get()
            ->keyBy(fn (LegacyIdentityMap $mapping): string => (string) $mapping->source_id);
    }

    private function sourceMapping(string $entity, string $sourceId, bool $lock = false): ?LegacyIdentityMap
    {
        $query = LegacyIdentityMap::query()
            ->where('source_product', self::PRODUCT)
            ->where('source_entity', $entity)
            ->where('source_id', $sourceId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /** @param list<string> $reasons */
    private function recordReview(string $entity, string $sourceId, array $reasons): bool
    {
        return DB::connection('core')->transaction(
            fn (): bool => $this->recordReviewInsideTransaction($entity, $sourceId, $reasons),
        );
    }

    /** @param list<string> $reasons */
    private function recordReviewInsideTransaction(string $entity, string $sourceId, array $reasons): bool
    {
        $mapping = $this->sourceMapping($entity, $sourceId, lock: true);

        if ($mapping !== null) {
            if ($mapping->status === 'needs_review') {
                $metadata = $mapping->metadata ?? [];
                if (isset($metadata['reason_codes']) && $metadata['reason_codes'] !== $reasons) {
                    $metadata['review_history'][] = [
                        'reason_codes' => $metadata['reason_codes'],
                        'notes' => $mapping->reconciliation_notes,
                        'recorded_at' => now()->toISOString(),
                    ];
                }
                $metadata['reason_codes'] = $reasons;
                $mapping->fill([
                    'reconciliation_notes' => implode('; ', $reasons),
                    'metadata' => $metadata,
                ])->save();
            }

            return false;
        }

        $canonicalEntity = match ($entity) {
            'billing_customer' => 'billing_customer',
            'subscription' => 'product_subscription',
            'current_subscription' => 'current_product_subscription',
            'billing_event' => 'product_billing_event',
            default => null,
        };

        LegacyIdentityMap::query()->create([
            'source_product' => self::PRODUCT,
            'source_entity' => $entity,
            'source_id' => $sourceId,
            'canonical_entity' => $canonicalEntity,
            'canonical_id' => null,
            'status' => 'needs_review',
            'batch_key' => self::BATCH_KEY,
            'reconciliation_notes' => implode('; ', $reasons),
            'metadata' => ['reason_codes' => $reasons],
            'imported_at' => now(),
        ]);

        return true;
    }

    /** @return array<string, mixed> */
    private function reconciledMetadata(?LegacyIdentityMap $mapping): array
    {
        $metadata = $mapping?->metadata ?? [];

        if (isset($metadata['reason_codes'])) {
            $metadata['review_history'][] = [
                'reason_codes' => $metadata['reason_codes'],
                'notes' => $mapping?->reconciliation_notes,
                'recorded_at' => $mapping?->updated_at?->toISOString(),
            ];
            unset($metadata['reason_codes']);
        }

        $metadata['imported_by'] = 'platform:import-monitor-subscriptions';

        return $metadata;
    }

    /** @param array<string, mixed> $extraMetadata */
    private function reconcileMap(
        string $entity,
        string $sourceId,
        string $canonicalEntity,
        string $canonicalId,
        ?LegacyIdentityMap $mapping,
        array $extraMetadata,
        mixed $now,
    ): void {
        $attributes = [
            'canonical_entity' => $canonicalEntity,
            'canonical_id' => $canonicalId,
            'status' => 'reconciled',
            'batch_key' => self::BATCH_KEY,
            'reconciliation_notes' => null,
            'metadata' => array_merge($this->reconciledMetadata($mapping), $extraMetadata),
            'imported_at' => $now,
            'reconciled_at' => $now,
        ];

        if ($mapping !== null) {
            $mapping->fill($attributes)->save();
        } else {
            LegacyIdentityMap::query()->create([
                'source_product' => self::PRODUCT,
                'source_entity' => $entity,
                'source_id' => $sourceId,
                ...$attributes,
            ]);
        }
    }

    private function planKeyForPrice(?string $priceId): ?string
    {
        if ($priceId === null) {
            return null;
        }

        foreach (config('monitor.beacon.plans', []) as $planKey => $plan) {
            if (is_array($plan) && ($plan['stripe_price_id'] ?? null) === $priceId) {
                return (string) $planKey;
            }
        }

        return null;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function preserveTimestamps(Model $target, object $source, mixed $updatedAt = null): void
    {
        $timestamps = [];
        if (isset($source->created_at)) {
            $timestamps['created_at'] = $source->created_at;
        }
        $updatedAt ??= $source->updated_at ?? null;
        if ($updatedAt !== null) {
            $timestamps['updated_at'] = $updatedAt;
        }

        if ($timestamps !== []) {
            $target->forceFill($timestamps)->saveQuietly();
        }
    }
}
