<?php

namespace App\Modules\Deployer\Services\Migration;

use App\Core\Models\BillingCustomer;
use App\Core\Models\CurrentProductSubscription;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\ProductSubscription;
use App\Core\Models\Workspace;
use App\Modules\Deployer\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Cashier\Subscription as CashierSubscription;
use Laravel\Cashier\SubscriptionItem as CashierSubscriptionItem;
use RuntimeException;

final class ImportSubscriptionsIntoCore
{
    private const PRODUCT = 'deployer';

    private const PROVIDER_ACCOUNT = 'deployer';

    private const BATCH_KEY = 'deployer-subscription-import-v1';

    /**
     * Preview or import Deployer's owner-billed subscriptions into workspace product slots.
     * Owners whose subscription is shared by multiple organizations are held for review.
     *
     * @return array{workspaces_seen:int,subscriptions_seen:int,subscriptions_without_workspace_owner:int,subscriptions_ready:int,subscriptions_imported:int,subscriptions_already_mapped:int,subscriptions_blocked:int,stripe_subscriptions_imported:int,billing_customers_imported:int,review_records_created:int}
     */
    public function run(bool $apply = false): array
    {
        foreach (['users', 'organizations', 'subscriptions', 'subscription_items'] as $table) {
            if (! Schema::connection('deployer')->hasTable($table)) {
                throw new RuntimeException("The Deployer {$table} table is unavailable on the deployer connection.");
            }
        }

        foreach ([
            'legacy_identity_maps', 'workspaces', 'billing_customers', 'product_subscriptions',
            'current_product_subscriptions',
        ] as $table) {
            if (! Schema::connection('core')->hasTable($table)) {
                throw new RuntimeException('Run the Core platform and product billing migrations before importing Deployer subscriptions.');
            }
        }

        $organizations = DB::connection('deployer')->table('organizations')->orderBy('id')->get();
        $users = DB::connection('deployer')->table('users')->get()->keyBy(fn (object $user): string => (string) $user->id);
        $subscriptions = DB::connection('deployer')->table('subscriptions')
            ->orderByDesc('created_at')->orderByDesc('id')->get()
            ->groupBy(fn (object $subscription): string => (string) $subscription->user_id);
        $subscriptionItems = DB::connection('deployer')->table('subscription_items')
            ->orderBy('subscription_id')->orderBy('id')->get()
            ->groupBy(fn (object $item): string => (string) $item->subscription_id);
        $organizationsByOwner = $organizations->groupBy(fn (object $organization): string => (string) $organization->owner_id);

        $userMaps = $this->mappingsFor('user');
        $workspaceMaps = $this->mappingsFor('organization');
        $currentMaps = $this->mappingsFor('current_subscription');
        $customerMaps = $this->mappingsFor('billing_customer');
        $subscriptionMaps = $this->mappingsFor('subscription');

        $report = [
            'workspaces_seen' => $organizations->count(),
            'subscriptions_seen' => $subscriptions->sum(fn (Collection $items): int => $items->count()),
            'subscriptions_without_workspace_owner' => 0,
            'subscriptions_ready' => 0,
            'subscriptions_imported' => 0,
            'subscriptions_already_mapped' => 0,
            'subscriptions_blocked' => 0,
            'stripe_subscriptions_imported' => 0,
            'billing_customers_imported' => 0,
            'review_records_created' => 0,
        ];

        foreach ($subscriptions as $sourceUserId => $ownerSubscriptions) {
            if ($organizationsByOwner->has((string) $sourceUserId)) {
                continue;
            }

            foreach ($ownerSubscriptions as $sourceSubscription) {
                $report['subscriptions_without_workspace_owner']++;
                if ($apply && $this->recordReview('subscription', (string) $sourceSubscription->id, [
                    'deployer_subscription_owner_has_no_organization',
                ], ['source_owner_user_id' => (string) $sourceUserId])) {
                    $report['review_records_created']++;
                }
            }
        }

        foreach ($organizations as $organization) {
            $sourceWorkspaceId = (string) $organization->id;
            $currentMap = $currentMaps->get($sourceWorkspaceId);

            if ($currentMap?->status === 'reconciled') {
                if ($this->currentMappingIsValid($currentMap, $workspaceMaps->get($sourceWorkspaceId))) {
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

            $sourceUserId = (string) $organization->owner_id;
            $sourceUser = $users->get($sourceUserId);
            $userMap = $userMaps->get($sourceUserId);
            $canonicalOwnerId = $this->canonicalUserId($userMap);
            $workspaceMap = $workspaceMaps->get($sourceWorkspaceId);
            $workspace = $this->canonicalWorkspace($workspaceMap);
            $ownerSubscriptions = $subscriptions->get($sourceUserId, collect());
            $billingState = $sourceUser === null
                ? null
                : $this->billingState($sourceUser, $ownerSubscriptions, $subscriptionItems);
            $reasons = [];

            if ($sourceUser === null || $canonicalOwnerId === null) {
                $reasons[] = 'deployer_workspace_owner_not_reconciled';
            }

            if ($workspace === null) {
                $reasons[] = 'deployer_organization_not_reconciled';
            } elseif ($canonicalOwnerId !== null && (string) $workspace->owner_user_id !== $canonicalOwnerId) {
                $reasons[] = 'deployer_workspace_owner_mapping_mismatch';
            }

            if ($organizationsByOwner->get($sourceUserId, collect())->count() > 1
                && ($ownerSubscriptions->isNotEmpty() || filled($sourceUser->stripe_id ?? null))) {
                $reasons[] = 'deployer_owner_billing_shared_across_workspaces';
            }

            if ($billingState !== null && $billingState['current_subscription_is_valid'] && $billingState['plan_key'] === 'free'
                && $billingState['current_subscription_has_price']) {
                $reasons[] = 'deployer_active_subscription_plan_price_unrecognized';
            }

            if ($workspace !== null && CurrentProductSubscription::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('product', self::PRODUCT)
                ->exists()) {
                $reasons[] = 'core_deployer_subscription_slot_already_occupied';
            }

            if ($sourceUser !== null && $workspace !== null) {
                $reasons = array_merge($reasons, $this->billingIdentityReviewReasons(
                    $sourceUser,
                    $workspace,
                    $customerMaps->get($sourceUserId),
                    $ownerSubscriptions,
                    $subscriptionMaps,
                ));
            }

            $reasons = array_values(array_unique($reasons));

            if ($reasons !== []) {
                $report['subscriptions_blocked']++;
                if ($apply && $this->recordReview('current_subscription', $sourceWorkspaceId, $reasons, [
                    'source_owner_user_id' => $sourceUserId,
                ])) {
                    $report['review_records_created']++;
                }

                continue;
            }

            $report['subscriptions_ready']++;

            if ($apply && $sourceUser !== null && $workspace !== null && $billingState !== null
                && $this->importWorkspaceSubscriptions(
                    $organization,
                    $sourceUser,
                    $workspace,
                    $ownerSubscriptions,
                    $subscriptionItems,
                    $billingState,
                    $customerMaps->get($sourceUserId),
                    $subscriptionMaps,
                    $report,
                )) {
                $report['subscriptions_imported']++;
            }
        }

        return $report;
    }

    /** @return array{plan_key:string,plan_snapshot:array<string,mixed>,billing_interval:string,current_subscription_id:?string,current_subscription_is_valid:bool,current_subscription_has_price:bool} */
    private function billingState(object $sourceUser, Collection $sourceSubscriptions, Collection $itemsBySubscription): array
    {
        $cashierSubscriptions = $sourceSubscriptions->map(function (object $source) use ($itemsBySubscription): CashierSubscription {
            $subscription = new CashierSubscription((array) $source);
            $items = $itemsBySubscription->get((string) $source->id, collect())
                ->map(fn (object $item): CashierSubscriptionItem => new CashierSubscriptionItem((array) $item));
            $subscription->setRelation('items', new EloquentCollection($items->all()));

            return $subscription;
        });

        $user = new User((array) $sourceUser);
        $user->setRelation('subscriptions', new EloquentCollection($cashierSubscriptions->all()));
        $currentSubscription = $user->subscription('default');
        $planKey = $user->billingPlan();

        return [
            'plan_key' => $planKey,
            'plan_snapshot' => config('billing.plans.'.$planKey, []),
            'billing_interval' => $planKey === 'free' ? 'monthly' : $user->billingInterval(),
            'current_subscription_id' => $currentSubscription === null ? null : (string) $currentSubscription->getKey(),
            'current_subscription_is_valid' => $currentSubscription?->valid() ?? false,
            'current_subscription_has_price' => $currentSubscription !== null
                && ($currentSubscription->stripe_price !== null || $currentSubscription->items->isNotEmpty()),
        ];
    }

    /** @param Collection<string, LegacyIdentityMap> $subscriptionMaps
     * @return list<string>
     */
    private function billingIdentityReviewReasons(
        object $sourceUser,
        Workspace $workspace,
        ?LegacyIdentityMap $customerMap,
        Collection $ownerSubscriptions,
        Collection $subscriptionMaps,
    ): array {
        $reasons = [];
        $stripeCustomerId = $this->stringValue($sourceUser->stripe_id ?? null);

        if ($stripeCustomerId !== null) {
            if ($customerMap?->status === 'reconciled') {
                $customer = $customerMap->canonical_entity === 'billing_customer'
                    ? BillingCustomer::query()->find($customerMap->canonical_id)
                    : null;

                if ($customer === null
                    || $customer->provider !== 'stripe'
                    || $customer->provider_account_key !== self::PROVIDER_ACCOUNT
                    || $customer->provider_customer_id !== $stripeCustomerId
                    || (string) $customer->workspace_id !== (string) $workspace->getKey()) {
                    $reasons[] = 'mapped_deployer_billing_customer_has_wrong_workspace_or_provider';
                }
            } else {
                if ($customerMap !== null && ($customerMap->status !== 'needs_review' || $customerMap->canonical_id !== null)) {
                    $reasons[] = 'existing_deployer_billing_customer_mapping_requires_review';
                }

                if (BillingCustomer::query()
                    ->where('provider', 'stripe')
                    ->where('provider_account_key', self::PROVIDER_ACCOUNT)
                    ->where('provider_customer_id', $stripeCustomerId)
                    ->exists()) {
                    $reasons[] = 'deployer_stripe_customer_id_already_exists_in_core';
                }
            }
        }

        foreach ($ownerSubscriptions as $sourceSubscription) {
            $sourceId = (string) $sourceSubscription->id;
            $mapping = $subscriptionMaps->get($sourceId);

            if ($mapping?->status === 'reconciled') {
                $subscription = $mapping->canonical_entity === 'product_subscription'
                    ? ProductSubscription::query()->find($mapping->canonical_id)
                    : null;

                if ($subscription === null
                    || (string) $subscription->workspace_id !== (string) $workspace->getKey()
                    || $subscription->product !== self::PRODUCT
                    || $subscription->provider !== 'stripe'
                    || $subscription->provider_account_key !== self::PROVIDER_ACCOUNT
                    || $subscription->provider_subscription_id !== (string) $sourceSubscription->stripe_id) {
                    $reasons[] = 'mapped_deployer_subscription_has_wrong_workspace_or_provider';
                }

                continue;
            }

            if ($mapping !== null && ($mapping->status !== 'needs_review' || $mapping->canonical_id !== null)) {
                $reasons[] = 'existing_deployer_subscription_mapping_requires_review';
            }

            if (ProductSubscription::query()
                ->where('provider', 'stripe')
                ->where('provider_account_key', self::PROVIDER_ACCOUNT)
                ->where('provider_subscription_id', $sourceSubscription->stripe_id)
                ->exists()) {
                $reasons[] = 'deployer_stripe_subscription_id_already_exists_in_core';
            }
        }

        return $reasons;
    }

    /** @param Collection<int, object> $sourceSubscriptions
     * @param  Collection<int, object>  $itemsBySubscription
     * @param  array<string, mixed>  $billingState
     * @param  Collection<string, LegacyIdentityMap>  $subscriptionMaps
     * @param  array<string, int>  $report
     */
    private function importWorkspaceSubscriptions(
        object $organization,
        object $sourceUser,
        Workspace $workspace,
        Collection $sourceSubscriptions,
        Collection $itemsBySubscription,
        array $billingState,
        ?LegacyIdentityMap $customerMap,
        Collection $subscriptionMaps,
        array &$report,
    ): bool {
        $sourceWorkspaceId = (string) $organization->id;
        $sourceUserId = (string) $sourceUser->id;

        return DB::connection('core')->transaction(function () use (
            $organization,
            $sourceUser,
            $workspace,
            $sourceSubscriptions,
            $itemsBySubscription,
            $billingState,
            $customerMap,
            $subscriptionMaps,
            $sourceWorkspaceId,
            $sourceUserId,
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
                if ($this->recordReviewInsideTransaction('current_subscription', $sourceWorkspaceId, ['core_deployer_subscription_slot_already_occupied'], [
                    'source_owner_user_id' => $sourceUserId,
                ])) {
                    $report['review_records_created']++;
                }

                return false;
            }

            $billingCustomerId = $this->importBillingCustomer($sourceUser, $workspace, $customerMap, $report);
            if ($billingCustomerId === false) {
                return false;
            }

            $importedSubscriptions = [];
            foreach ($sourceSubscriptions as $sourceSubscription) {
                $items = $itemsBySubscription->get((string) $sourceSubscription->id, collect());
                $subscription = $this->importSubscription(
                    $sourceUser,
                    $workspace,
                    $billingCustomerId,
                    $sourceSubscription,
                    $items,
                    $subscriptionMaps->get((string) $sourceSubscription->id),
                    $report,
                );

                if ($subscription === false) {
                    return false;
                }

                $importedSubscriptions[(string) $sourceSubscription->id] = $subscription;
            }

            $currentSubscription = $billingState['current_subscription_id'] === null
                ? null
                : ($importedSubscriptions[$billingState['current_subscription_id']] ?? null);

            if (! $billingState['current_subscription_is_valid'] || $billingState['plan_key'] === 'free') {
                $currentSubscription = ProductSubscription::query()->create([
                    'workspace_id' => $workspace->getKey(),
                    'billing_customer_id' => $billingCustomerId,
                    'product' => self::PRODUCT,
                    'provider' => 'deployer_legacy',
                    'provider_account_key' => self::PROVIDER_ACCOUNT,
                    'provider_subscription_id' => null,
                    'provider_price_id' => null,
                    'plan_key' => 'free',
                    'status' => 'active',
                    'quantity' => 1,
                    'metadata' => [
                        'migration_source' => 'deployer',
                        'source_organization_id' => $sourceWorkspaceId,
                        'source_owner_user_id' => $sourceUserId,
                        'source_customer_id' => $sourceUser->stripe_id ?? null,
                        'source_current_subscription_id' => $billingState['current_subscription_id'],
                        'plan_snapshot' => $billingState['plan_snapshot'],
                        'entitlement_snapshot' => true,
                    ],
                ]);
                $this->preserveTimestamps($currentSubscription, $organization, $organization->updated_at ?? null);
            } elseif ($currentSubscription === null) {
                if ($this->recordReviewInsideTransaction('current_subscription', $sourceWorkspaceId, ['current_deployer_subscription_record_missing'], [
                    'source_owner_user_id' => $sourceUserId,
                ])) {
                    $report['review_records_created']++;
                }

                return false;
            }

            $currentSubscription->forceFill([
                'metadata' => array_merge($currentSubscription->metadata ?? [], [
                    'billing_interval' => $billingState['billing_interval'],
                    'plan_snapshot' => $billingState['plan_snapshot'],
                    'legacy_owner_billing' => [
                        'source_owner_user_id' => $sourceUserId,
                        'source_organization_id' => $sourceWorkspaceId,
                        'source_customer_id' => $sourceUser->stripe_id ?? null,
                        'source_current_subscription_id' => $billingState['current_subscription_id'],
                        'shared_across_workspaces' => false,
                    ],
                ]),
            ])->save();

            $assignment = CurrentProductSubscription::query()->create([
                'workspace_id' => $workspace->getKey(),
                'product' => self::PRODUCT,
                'product_subscription_id' => $currentSubscription->getKey(),
            ]);

            $now = now();
            $mapAttributes = [
                'canonical_entity' => 'current_product_subscription',
                'canonical_id' => (string) $assignment->getKey(),
                'status' => 'reconciled',
                'batch_key' => self::BATCH_KEY,
                'reconciliation_notes' => null,
                'metadata' => array_merge($this->reconciledMetadata($lockedCurrentMap), [
                    'source_owner_user_id' => $sourceUserId,
                    'source_organization_id' => $sourceWorkspaceId,
                    'product_subscription_id' => (string) $currentSubscription->getKey(),
                    'plan_key' => $billingState['plan_key'],
                    'provider' => $currentSubscription->provider,
                    'provider_subscription_id' => $currentSubscription->provider_subscription_id,
                ]),
                'imported_at' => $now,
                'reconciled_at' => $now,
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
    private function importBillingCustomer(object $sourceUser, Workspace $workspace, ?LegacyIdentityMap $existingMap, array &$report): string|false|null
    {
        $sourceUserId = (string) $sourceUser->id;
        $stripeCustomerId = $this->stringValue($sourceUser->stripe_id ?? null);
        if ($stripeCustomerId === null) {
            return null;
        }

        $mapping = $this->sourceMapping('billing_customer', $sourceUserId, lock: true) ?? $existingMap;
        if ($mapping !== null) {
            if ($mapping->status === 'reconciled' && $mapping->canonical_entity === 'billing_customer') {
                $customer = BillingCustomer::query()->find($mapping->canonical_id);

                if ($customer !== null
                    && $customer->provider === 'stripe'
                    && $customer->provider_account_key === self::PROVIDER_ACCOUNT
                    && $customer->provider_customer_id === $stripeCustomerId
                    && (string) $customer->workspace_id === (string) $workspace->getKey()) {
                    return (string) $customer->getKey();
                }

                if ($this->recordReviewInsideTransaction('billing_customer', $sourceUserId, ['mapped_deployer_billing_customer_has_wrong_workspace_or_provider'])) {
                    $report['review_records_created']++;
                }

                return false;
            }

            if ($mapping->status !== 'needs_review' || $mapping->canonical_id !== null) {
                if ($this->recordReviewInsideTransaction('billing_customer', $sourceUserId, ['existing_deployer_billing_customer_mapping_requires_review'])) {
                    $report['review_records_created']++;
                }

                return false;
            }
        }

        if (BillingCustomer::query()
            ->where('provider', 'stripe')
            ->where('provider_account_key', self::PROVIDER_ACCOUNT)
            ->where('provider_customer_id', $stripeCustomerId)
            ->exists()) {
            if ($this->recordReviewInsideTransaction('billing_customer', $sourceUserId, ['deployer_stripe_customer_id_already_exists_in_core'])) {
                $report['review_records_created']++;
            }

            return false;
        }

        $customer = BillingCustomer::query()->create([
            'workspace_id' => $workspace->getKey(),
            'provider' => 'stripe',
            'provider_account_key' => self::PROVIDER_ACCOUNT,
            'provider_customer_id' => $stripeCustomerId,
            'status' => 'active',
            'metadata' => [
                'migration_source' => 'deployer',
                'source_user_id' => $sourceUserId,
                'payment_method_type' => $sourceUser->pm_type ?? null,
                'payment_method_last_four' => $sourceUser->pm_last_four ?? null,
            ],
        ]);
        $this->preserveTimestamps($customer, $sourceUser);
        $this->reconcileMap('billing_customer', $sourceUserId, 'billing_customer', (string) $customer->getKey(), $mapping, [
            'source_user_id' => $sourceUserId,
        ], now());
        $report['billing_customers_imported']++;

        return (string) $customer->getKey();
    }

    /** @param Collection<int, object> $sourceItems
     * @param  array<string, int>  $report
     */
    private function importSubscription(
        object $sourceUser,
        Workspace $workspace,
        string|false|null $billingCustomerId,
        object $sourceSubscription,
        Collection $sourceItems,
        ?LegacyIdentityMap $existingMap,
        array &$report,
    ): ProductSubscription|false {
        $sourceId = (string) $sourceSubscription->id;
        $stripeSubscriptionId = (string) $sourceSubscription->stripe_id;
        $mapping = $this->sourceMapping('subscription', $sourceId, lock: true) ?? $existingMap;

        if ($mapping !== null) {
            if ($mapping->status === 'reconciled' && $mapping->canonical_entity === 'product_subscription') {
                $subscription = ProductSubscription::query()->find($mapping->canonical_id);

                if ($subscription !== null
                    && (string) $subscription->workspace_id === (string) $workspace->getKey()
                    && $subscription->product === self::PRODUCT
                    && $subscription->provider === 'stripe'
                    && $subscription->provider_account_key === self::PROVIDER_ACCOUNT
                    && $subscription->provider_subscription_id === $stripeSubscriptionId) {
                    return $subscription;
                }

                if ($this->recordReviewInsideTransaction('subscription', $sourceId, ['mapped_deployer_subscription_has_wrong_workspace_or_provider'])) {
                    $report['review_records_created']++;
                }

                return false;
            }

            if ($mapping->status !== 'needs_review' || $mapping->canonical_id !== null) {
                if ($this->recordReviewInsideTransaction('subscription', $sourceId, ['existing_deployer_subscription_mapping_requires_review'])) {
                    $report['review_records_created']++;
                }

                return false;
            }
        }

        if (ProductSubscription::query()
            ->where('provider', 'stripe')
            ->where('provider_account_key', self::PROVIDER_ACCOUNT)
            ->where('provider_subscription_id', $stripeSubscriptionId)
            ->exists()) {
            if ($this->recordReviewInsideTransaction('subscription', $sourceId, ['deployer_stripe_subscription_id_already_exists_in_core'])) {
                $report['review_records_created']++;
            }

            return false;
        }

        $planKey = $this->planKeyForPrice($sourceSubscription, $sourceItems);
        $priceId = $this->providerPriceId($sourceSubscription, $sourceItems, $planKey);
        $status = (string) $sourceSubscription->stripe_status;
        $endsAt = $sourceSubscription->ends_at ?? null;
        $subscription = ProductSubscription::query()->create([
            'workspace_id' => $workspace->getKey(),
            'billing_customer_id' => $billingCustomerId,
            'product' => self::PRODUCT,
            'provider' => 'stripe',
            'provider_account_key' => self::PROVIDER_ACCOUNT,
            'provider_subscription_id' => $stripeSubscriptionId,
            'provider_price_id' => $priceId,
            'plan_key' => $planKey,
            'status' => $status,
            'quantity' => (int) ($sourceSubscription->quantity ?? 1),
            'trial_ends_at' => $sourceSubscription->trial_ends_at ?? null,
            'current_period_starts_at' => null,
            'current_period_ends_at' => null,
            'cancel_at' => $endsAt !== null && Carbon::parse($endsAt)->isFuture() ? $endsAt : null,
            'canceled_at' => $status === 'canceled' && $endsAt !== null && Carbon::parse($endsAt)->isPast() ? $endsAt : null,
            'metadata' => [
                'migration_source' => 'deployer',
                'source_user_id' => (string) $sourceUser->id,
                'source_subscription_id' => $sourceId,
                'source_subscription_type' => (string) $sourceSubscription->type,
                'source_stripe_price' => $sourceSubscription->stripe_price,
                'source_ends_at' => $endsAt,
                'source_quantity' => $sourceSubscription->quantity,
                'plan_snapshot' => $planKey === null ? null : config('billing.plans.'.$planKey, []),
                'items' => $sourceItems->map(fn (object $item): array => [
                    'source_item_id' => (string) $item->id,
                    'stripe_item_id' => (string) $item->stripe_id,
                    'stripe_product_id' => (string) $item->stripe_product,
                    'stripe_price_id' => (string) $item->stripe_price,
                    'quantity' => $item->quantity === null ? null : (int) $item->quantity,
                    'created_at' => $item->created_at,
                    'updated_at' => $item->updated_at,
                ])->values()->all(),
            ],
        ]);
        $this->preserveTimestamps($subscription, $sourceSubscription);
        $this->reconcileMap('subscription', $sourceId, 'product_subscription', (string) $subscription->getKey(), $mapping, [
            'source_user_id' => (string) $sourceUser->id,
            'source_stripe_subscription_id' => $stripeSubscriptionId,
        ], now());
        $report['stripe_subscriptions_imported']++;

        return $subscription;
    }

    private function currentMappingIsValid(LegacyIdentityMap $mapping, ?LegacyIdentityMap $workspaceMap): bool
    {
        if ($mapping->canonical_entity !== 'current_product_subscription'
            || $workspaceMap?->status !== 'reconciled'
            || $workspaceMap->canonical_entity !== 'workspace') {
            return false;
        }

        $assignment = CurrentProductSubscription::query()->find($mapping->canonical_id);
        if ($assignment === null
            || (string) $assignment->workspace_id !== (string) $workspaceMap->canonical_id
            || $assignment->product !== self::PRODUCT) {
            return false;
        }

        return ProductSubscription::query()
            ->whereKey($assignment->product_subscription_id)
            ->where('workspace_id', $workspaceMap->canonical_id)
            ->where('product', self::PRODUCT)
            ->exists();
    }

    private function canonicalWorkspace(?LegacyIdentityMap $mapping): ?Workspace
    {
        if ($mapping?->status !== 'reconciled' || $mapping->canonical_entity !== 'workspace') {
            return null;
        }

        return Workspace::query()->find($mapping->canonical_id);
    }

    private function canonicalUserId(?LegacyIdentityMap $mapping): ?string
    {
        return $mapping?->status === 'reconciled' && $mapping->canonical_entity === 'user'
            ? (string) $mapping->canonical_id
            : null;
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

    /** @param list<string> $reasons
     * @param  array<string, mixed>  $extraMetadata
     */
    private function recordReview(string $entity, string $sourceId, array $reasons, array $extraMetadata = []): bool
    {
        return DB::connection('core')->transaction(
            fn (): bool => $this->recordReviewInsideTransaction($entity, $sourceId, $reasons, $extraMetadata),
        );
    }

    /** @param list<string> $reasons
     * @param  array<string, mixed>  $extraMetadata
     */
    private function recordReviewInsideTransaction(string $entity, string $sourceId, array $reasons, array $extraMetadata = []): bool
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
                    'metadata' => array_merge($metadata, $extraMetadata),
                ])->save();
            }

            return false;
        }

        $canonicalEntity = match ($entity) {
            'billing_customer' => 'billing_customer',
            'subscription' => 'product_subscription',
            'current_subscription' => 'current_product_subscription',
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
            'metadata' => array_merge(['reason_codes' => $reasons], $extraMetadata),
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

        $metadata['imported_by'] = 'platform:import-deployer-subscriptions';

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

    /** @param Collection<int, object> $sourceItems */
    private function planKeyForPrice(object $sourceSubscription, Collection $sourceItems): ?string
    {
        $prices = $sourceItems->pluck('stripe_price')->push($sourceSubscription->stripe_price)
            ->filter(fn (mixed $price): bool => is_string($price) && trim($price) !== '')
            ->all();

        foreach (array_reverse(config('billing.plans', []), true) as $planKey => $plan) {
            if (! is_array($plan)) {
                continue;
            }

            $planPrices = array_filter(array_unique([
                $plan['price_id'] ?? null,
                $plan['monthly_price_id'] ?? null,
                $plan['yearly_price_id'] ?? null,
            ]));

            if (array_intersect($prices, $planPrices) !== []) {
                return (string) $planKey;
            }
        }

        return null;
    }

    /** @param Collection<int, object> $sourceItems */
    private function providerPriceId(object $sourceSubscription, Collection $sourceItems, ?string $planKey): ?string
    {
        $sourcePrice = $this->stringValue($sourceSubscription->stripe_price ?? null);
        if ($sourcePrice !== null) {
            return $sourcePrice;
        }

        if ($planKey === null) {
            return null;
        }

        $plan = config('billing.plans.'.$planKey, []);
        $planPrices = array_filter(array_unique([
            $plan['price_id'] ?? null,
            $plan['monthly_price_id'] ?? null,
            $plan['yearly_price_id'] ?? null,
        ]));

        return $sourceItems->first(fn (object $item): bool => in_array($item->stripe_price, $planPrices, true))?->stripe_price;
    }

    private function preserveTimestamps(Model $target, object $source, mixed $updatedAt = null): void
    {
        $target->forceFill([
            'created_at' => $source->created_at ?? now(),
            'updated_at' => $updatedAt ?? $source->updated_at ?? $source->created_at ?? now(),
        ])->saveQuietly();
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
