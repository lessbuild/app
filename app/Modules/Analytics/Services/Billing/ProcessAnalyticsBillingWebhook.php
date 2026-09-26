<?php

namespace App\Modules\Analytics\Services\Billing;

use App\Core\Models\AnalyticsCheckoutAttempt;
use App\Core\Models\BillingCustomer;
use App\Core\Models\CurrentProductSubscription;
use App\Core\Models\ProductBillingEvent;
use App\Core\Models\ProductSubscription;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Analytics\Models\Workspace as AnalyticsWorkspace;
use App\Modules\Analytics\Services\Deletion\AnalyticsDeletionFence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Projects only authoritative Analytics subscription state into its Core plan slot. */
final class ProcessAnalyticsBillingWebhook
{
    private const SUBSCRIPTION_EVENTS = [
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'invoice.paid',
        'invoice.payment_failed',
    ];

    public function __construct(
        private readonly AnalyticsStripeClient $stripe,
        private readonly AnalyticsBillingCatalog $catalog,
        private readonly LegacyIdentityResolver $identities,
        private readonly AnalyticsDeletionFence $deletionFence,
    ) {}

    /** @param array<string, mixed> $verifiedEvent */
    public function handle(array $verifiedEvent): bool
    {
        if (! config('analytics.billing.webhooks_enabled', false) || ! $this->stripe->configured()) {
            throw new RuntimeException('analytics_billing_not_configured');
        }

        $accountId = config('analytics.billing.stripe.account_id');
        if (! is_string($accountId) || ! preg_match('/^acct_[A-Za-z0-9]+$/', $accountId)
            || $this->stripe->retrieveAccountId() !== $accountId) {
            throw new RuntimeException('analytics_provider_account_unverified');
        }

        $eventId = $verifiedEvent['id'] ?? null;
        if (! is_string($eventId) || ! preg_match('/^evt_[A-Za-z0-9]+$/', $eventId)
            || (isset($verifiedEvent['account']) && $verifiedEvent['account'] !== $accountId)
            || ($verifiedEvent['livemode'] ?? null) !== $this->expectedLiveMode()) {
            throw new RuntimeException('analytics_event_account_or_identity_invalid');
        }

        $eventType = $verifiedEvent['type'] ?? null;
        if (! is_string($eventType) || $eventType === '') {
            throw new RuntimeException('analytics_event_type_invalid');
        }
        $createdAt = $this->timestamp($verifiedEvent['created'] ?? null)?->timestamp;
        $this->receipt($accountId, $eventId, $eventType, $createdAt);

        $existing = $this->event($accountId, $eventId);
        if (in_array($existing?->processing_status, ['applied', 'ignored'], true)) {
            return false;
        }

        $boundWorkspaceId = null;
        try {
            // Fetch through Analytics-only credentials. Neither client metadata
            // nor a same-second event timestamp decides current state.
            $event = $this->stripe->retrieveEvent($eventId);
            if (($event['id'] ?? null) !== $eventId
                || ($event['type'] ?? null) !== $eventType
                || (isset($event['account']) && $event['account'] !== $accountId)
                || ($event['livemode'] ?? null) !== $this->expectedLiveMode()) {
                throw new AnalyticsBillingReconciliationFailure('event_provider_mismatch');
            }

            if (! in_array($eventType, self::SUBSCRIPTION_EVENTS, true)) {
                $existing->forceFill([
                    'processing_status' => 'ignored',
                    'ignored_reason' => 'not_subscription_state',
                    'processed_at' => now('UTC'),
                ])->save();

                return true;
            }

            $subscriptionId = $this->subscriptionId($event);
            $subscription = $this->stripe->retrieveSubscription($subscriptionId);
            $this->validateSubscription($subscription, $subscriptionId, $accountId);
            $workspaceId = data_get($subscription, 'metadata.core_workspace_id');
            $attemptId = data_get($subscription, 'metadata.checkout_attempt_id');
            if (! is_string($workspaceId) || ! Str::isUlid($workspaceId)
                || ! is_string($attemptId) || ! Str::isUlid($attemptId)) {
                throw new AnalyticsBillingReconciliationFailure('subscription_mapping_missing');
            }
            $nativeId = data_get($subscription, 'metadata.analytics_workspace_id');
            $attemptBinding = AnalyticsCheckoutAttempt::query()->find($attemptId);
            if (! is_scalar($nativeId) || ! ctype_digit((string) $nativeId)
                || $attemptBinding === null
                || (string) $attemptBinding->core_workspace_id !== $workspaceId
                || (string) $attemptBinding->analytics_workspace_id !== (string) $nativeId
                || $attemptBinding->provider_account_key !== $accountId
                || $this->identities->sourceIdsForCanonical('analytics', 'workspace', $workspaceId, 'workspace') !== [(string) $nativeId]
                || $this->identities->canonicalIdForSource('analytics', 'workspace', (string) $nativeId, 'workspace') !== $workspaceId
                || ! AnalyticsWorkspace::query()->whereKey($nativeId)->exists()) {
                throw new AnalyticsBillingReconciliationFailure('workspace_mapping_unreconciled');
            }
            $boundWorkspaceId = $workspaceId;
            ProductBillingEvent::query()->where('provider', 'stripe')
                ->where('provider_account_key', $accountId)
                ->where('provider_event_id', $eventId)
                ->where('product', 'analytics')
                ->where('processing_status', 'pending')
                ->update(['workspace_id' => $workspaceId]);

            // Every event for this subscription names the same checkout attempt.
            // Take a write lock before any read: production Core uses SQLite,
            // where SELECT FOR UPDATE does not serialize readers. Keep this
            // transaction through the fresh provider read and projection. A
            // busy lock fails safely, leaving the receipt pending for retry.
            $reconciled = DB::connection('core')->transaction(function () use ($accountId, $eventId, $workspaceId, $attemptId, $subscriptionId): bool {
                $locked = DB::connection('core')->table('workspaces')
                    ->where('id', $workspaceId)
                    ->update(['updated_at' => now('UTC')]);
                if ($locked !== 1) {
                    throw new AnalyticsBillingReconciliationFailure('core_workspace_unavailable');
                }
                $workspace = CoreWorkspace::query()->whereKey($workspaceId)->lockForUpdate()->first();
                $attempt = AnalyticsCheckoutAttempt::query()->whereKey($attemptId)->lockForUpdate()->first();
                if ($workspace === null || $attempt === null
                    || (string) $attempt->core_workspace_id !== $workspaceId
                    || $attempt->provider_account_key !== $accountId) {
                    throw new AnalyticsBillingReconciliationFailure('checkout_binding_unreconciled');
                }

                $fresh = $this->stripe->retrieveSubscription($subscriptionId);
                $this->validateSubscription($fresh, $subscriptionId, $accountId);
                if (data_get($fresh, 'metadata.core_workspace_id') !== $workspaceId
                    || data_get($fresh, 'metadata.checkout_attempt_id') !== $attemptId) {
                    throw new AnalyticsBillingReconciliationFailure('subscription_mapping_changed');
                }

                return $this->project($accountId, $eventId, $fresh, $subscriptionId);
            }, attempts: 3);
            if (! $reconciled) {
                // Commit the denied paid assignment and retryable receipt before
                // asking Stripe to retry an unapproved price.
                throw new AnalyticsBillingReconciliationFailure('price_not_allowlisted');
            }
        } catch (Throwable $exception) {
            $reason = $exception instanceof AnalyticsBillingReconciliationFailure
                ? $exception->getMessage()
                : 'provider_or_projection_unavailable';
            $failureUpdate = ['ignored_reason' => $reason, 'updated_at' => now('UTC')];
            if ($boundWorkspaceId !== null) {
                $failureUpdate['workspace_id'] = $boundWorkspaceId;
            }
            ProductBillingEvent::query()
                ->where('provider', 'stripe')
                ->where('provider_account_key', $accountId)
                ->where('provider_event_id', $eventId)
                ->where('product', 'analytics')
                ->where('processing_status', 'pending')
                ->update($failureUpdate);

            throw $exception;
        }

        return true;
    }

    private function receipt(string $accountId, string $eventId, string $eventType, ?int $createdAt): void
    {
        $now = now('UTC');
        DB::connection('core')->table('product_billing_events')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'workspace_id' => null,
            'product' => 'analytics',
            'provider' => 'stripe',
            'provider_account_key' => $accountId,
            'provider_event_id' => $eventId,
            'event_type' => $eventType,
            'provider_created_at' => $createdAt,
            'processing_status' => 'pending',
            'processed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $receipt = $this->event($accountId, $eventId);
        if ($receipt === null || $receipt->product !== 'analytics' || $receipt->event_type !== $eventType) {
            throw new RuntimeException('analytics_event_receipt_scope_conflict');
        }
    }

    private function event(string $accountId, string $eventId): ?ProductBillingEvent
    {
        return ProductBillingEvent::query()
            ->where('provider', 'stripe')
            ->where('provider_account_key', $accountId)
            ->where('provider_event_id', $eventId)
            ->first();
    }

    /** @param array<string, mixed> $event */
    private function subscriptionId(array $event): string
    {
        $object = $event['data']['object'] ?? null;
        if (! is_array($object)) {
            throw new AnalyticsBillingReconciliationFailure('event_object_invalid');
        }

        $id = str_starts_with((string) $event['type'], 'customer.subscription.')
            ? ($object['id'] ?? null)
            : ($object['subscription'] ?? data_get($object, 'parent.subscription_details.subscription'));

        if (! is_string($id) || ! preg_match('/^sub_[A-Za-z0-9]+$/', $id)) {
            throw new AnalyticsBillingReconciliationFailure('subscription_identity_missing');
        }

        return $id;
    }

    /** @param array<string, mixed> $subscription */
    private function validateSubscription(array $subscription, string $subscriptionId, string $accountId): void
    {
        $metadata = $subscription['metadata'] ?? null;
        if (($subscription['id'] ?? null) !== $subscriptionId
            || ($subscription['object'] ?? null) !== 'subscription'
            || ($subscription['livemode'] ?? null) !== $this->expectedLiveMode()
            || ! is_array($metadata)
            || ($metadata['product'] ?? null) !== 'analytics'
            || ($metadata['provider_account_key'] ?? null) !== $accountId
            || ! preg_match('/^cus_[A-Za-z0-9]+$/', (string) ($subscription['customer'] ?? ''))
            || ! in_array($subscription['status'] ?? null, [
                'active', 'trialing', 'past_due', 'unpaid', 'canceled', 'incomplete', 'incomplete_expired', 'paused',
            ], true)) {
            throw new AnalyticsBillingReconciliationFailure('subscription_binding_invalid');
        }
    }

    /** @param array<string, mixed> $subscription */
    private function project(string $accountId, string $eventId, array $subscription, string $subscriptionId): bool
    {
        $metadata = $subscription['metadata'];
        $workspaceId = $metadata['core_workspace_id'] ?? null;
        $nativeId = $metadata['analytics_workspace_id'] ?? null;
        $attemptId = $metadata['checkout_attempt_id'] ?? null;
        $customerId = (string) $subscription['customer'];
        $priceId = $this->priceId($subscription);

        if (in_array($subscription['status'], ['active', 'trialing'], true)
            && $this->periodTimestamp($subscription, 'current_period_end') === null) {
            throw new AnalyticsBillingReconciliationFailure('subscription_period_missing');
        }

        if (! is_string($workspaceId) || ! Str::isUlid($workspaceId)
            || ! is_scalar($nativeId) || ! ctype_digit((string) $nativeId)
            || ! is_string($attemptId) || ! Str::isUlid($attemptId)) {
            throw new AnalyticsBillingReconciliationFailure('subscription_mapping_missing');
        }

        $sourceIds = $this->identities->sourceIdsForCanonical('analytics', 'workspace', $workspaceId, 'workspace');
        if ($sourceIds !== [(string) $nativeId]
            || $this->identities->canonicalIdForSource('analytics', 'workspace', (string) $nativeId, 'workspace') !== $workspaceId
            || ! AnalyticsWorkspace::query()->whereKey($nativeId)->exists()) {
            throw new AnalyticsBillingReconciliationFailure('workspace_mapping_unreconciled');
        }

        $bound = AnalyticsCheckoutAttempt::query()->find($attemptId);
        if ($bound === null
            || (string) $bound->core_workspace_id !== $workspaceId
            || (string) $bound->analytics_workspace_id !== (string) $nativeId
            || $bound->provider_account_key !== $accountId
            || ($bound->provider_customer_id !== null && $bound->provider_customer_id !== $customerId)
            || ($bound->provider_subscription_id !== null && $bound->provider_subscription_id !== $subscriptionId)
            || ! is_string($bound->provider_checkout_session_id)) {
            throw new AnalyticsBillingReconciliationFailure('checkout_binding_unreconciled');
        }

        if ($bound->provider_subscription_id === null) {
            $session = $this->stripe->retrieveCheckoutSession($bound->provider_checkout_session_id);
            if (($session['id'] ?? null) !== $bound->provider_checkout_session_id
                || ($session['mode'] ?? null) !== 'subscription'
                || ($session['status'] ?? null) !== 'complete'
                || ($session['subscription'] ?? null) !== $subscriptionId
                || ($session['customer'] ?? null) !== $customerId
                || data_get($session, 'metadata.product') !== 'analytics'
                || data_get($session, 'metadata.checkout_attempt_id') !== $attemptId
                || data_get($session, 'metadata.core_workspace_id') !== $workspaceId
                || data_get($session, 'metadata.analytics_workspace_id') !== (string) $nativeId
                || data_get($session, 'metadata.provider_account_key') !== $accountId) {
                throw new AnalyticsBillingReconciliationFailure('checkout_session_conflict');
            }
        }

        $catalogPlan = $this->catalog->forPrice($priceId);
        $knownPriceMismatch = false;
        if ($bound->provider_subscription_id === null && $bound->provider_price_id === $priceId) {
            // A config rollout must not change what this checkout purchased.
            // The original approved price terms and allowance are durable on
            // the attempt and remain valid even if the live catalog rotates.
            $terms = $bound->price_terms;
            $snapshot = $bound->plan_snapshot;
            $catalogPlan = is_array($terms)
                && is_array($snapshot)
                && $this->validSnapshot($snapshot)
                && is_string($bound->plan_key)
                && preg_match('/^[a-z][a-z0-9_-]{0,99}$/', $bound->plan_key)
                && is_string($bound->price_terms_hash)
                && $this->validStoredTermsHash($terms, $bound->price_terms_hash)
                && ($terms['price_id'] ?? null) === $priceId
                && $this->priceMatchesTerms($priceId, $terms)
                    ? ['key' => $bound->plan_key, 'snapshot' => $snapshot]
                    : null;
        } elseif ($catalogPlan !== null && ! $this->priceMatchesTerms($priceId, $this->catalog->priceTerms($catalogPlan))) {
            $catalogPlan = null;
            $knownPriceMismatch = true;
        }

        $reconciled = false;
        DB::connection('core')->transaction(function () use (
            $accountId, $eventId, $subscription, $subscriptionId, $workspaceId, $nativeId,
            $attemptId, $customerId, $priceId, $catalogPlan, $knownPriceMismatch, &$reconciled
        ): void {
            $receipt = ProductBillingEvent::query()->where('provider', 'stripe')
                ->where('provider_account_key', $accountId)
                ->where('provider_event_id', $eventId)
                ->lockForUpdate()->firstOrFail();
            if ($receipt->product !== 'analytics') {
                throw new AnalyticsBillingReconciliationFailure('event_receipt_scope_conflict');
            }
            if ($receipt->processing_status !== 'pending') {
                $reconciled = in_array($receipt->processing_status, ['applied', 'ignored'], true);

                return;
            }

            $workspace = CoreWorkspace::query()->whereKey($workspaceId)->lockForUpdate()->first();
            if ($workspace === null || $workspace->status === 'deleted') {
                throw new AnalyticsBillingReconciliationFailure('core_workspace_unavailable');
            }

            $attempt = AnalyticsCheckoutAttempt::query()->whereKey($attemptId)->lockForUpdate()->first();
            if ($attempt === null || (string) $attempt->core_workspace_id !== $workspaceId
                || (string) $attempt->analytics_workspace_id !== (string) $nativeId
                || $attempt->provider_account_key !== $accountId
                || ($attempt->provider_customer_id !== null && $attempt->provider_customer_id !== $customerId)
                || ($attempt->provider_subscription_id !== null && $attempt->provider_subscription_id !== $subscriptionId)) {
                throw new AnalyticsBillingReconciliationFailure('checkout_binding_changed');
            }

            $current = CurrentProductSubscription::query()
                ->where('workspace_id', $workspaceId)->where('product', 'analytics')
                ->lockForUpdate()->first();
            $prior = $current?->subscription;
            if ($prior !== null && ((string) $prior->workspace_id !== $workspaceId || $prior->product !== 'analytics')) {
                throw new AnalyticsBillingReconciliationFailure('current_plan_scope_conflict');
            }

            if ($catalogPlan === null && ! $knownPriceMismatch && $prior !== null
                && $prior->provider === 'stripe'
                && $prior->provider_account_key === $accountId
                && $prior->provider_subscription_id === $subscriptionId
                && $prior->provider_price_id === $priceId
                && $prior->status !== 'unverified_price'
                && is_array($prior->metadata['plan_snapshot'] ?? null)
                && $this->validSnapshot($prior->metadata['plan_snapshot'])) {
                // Existing verified assignments retain their immutable grant
                // when a price is removed from the purchase catalog.
                $catalogPlan = ['key' => $prior->plan_key, 'snapshot' => $prior->metadata['plan_snapshot']];
            }

            if ($workspace->status !== 'active' || $workspace->archived_at !== null
                || $this->deletionFence->isFenced('workspace', (string) $nativeId)) {
                if ($prior === null || $prior->provider !== 'stripe'
                    || $prior->provider_account_key !== $accountId
                    || $prior->provider_subscription_id !== $subscriptionId) {
                    throw new AnalyticsBillingReconciliationFailure('workspace_lifecycle_fenced');
                }
            }

            $conflictingCustomer = BillingCustomer::query()->where('provider', 'stripe')
                ->where('provider_account_key', $accountId)
                ->where('provider_customer_id', $customerId)->lockForUpdate()->first();
            if ($conflictingCustomer !== null && (string) $conflictingCustomer->workspace_id !== $workspaceId) {
                throw new AnalyticsBillingReconciliationFailure('customer_workspace_conflict');
            }

            $customer = $conflictingCustomer ?? BillingCustomer::query()->create([
                'workspace_id' => $workspaceId,
                'provider' => 'stripe',
                'provider_account_key' => $accountId,
                'provider_customer_id' => $customerId,
                'status' => 'active',
            ]);

            $matches = ProductSubscription::query()->where('provider', 'stripe')
                ->where('provider_account_key', $accountId)
                ->where('provider_subscription_id', $subscriptionId)
                ->lockForUpdate()->get();
            if ($matches->contains(fn (ProductSubscription $item): bool => $item->product !== 'analytics'
                || (string) $item->workspace_id !== $workspaceId
                || (string) $item->billing_customer_id !== (string) $customer->getKey())) {
                throw new AnalyticsBillingReconciliationFailure('subscription_scope_conflict');
            }

            if ($prior !== null && $prior->provider === 'stripe'
                && ($prior->provider_account_key !== $accountId
                    || ($prior->provider_subscription_id !== $subscriptionId
                        && ! in_array($prior->status, ['canceled', 'unpaid', 'incomplete_expired'], true)))) {
                throw new AnalyticsBillingReconciliationFailure('active_subscription_conflict');
            }

            if ($catalogPlan === null) {
                if ($prior !== null && $prior->provider === 'stripe'
                    && $prior->provider_account_key === $accountId
                    && $prior->provider_subscription_id === $subscriptionId
                    && $prior->provider_price_id === $priceId) {
                    $prior->forceFill(['status' => 'unverified_price'])->save();
                } else {
                    $unverified = ProductSubscription::query()->create([
                        'workspace_id' => $workspaceId,
                        'billing_customer_id' => $customer->getKey(),
                        'product' => 'analytics',
                        'provider' => 'stripe',
                        'provider_account_key' => $accountId,
                        'provider_subscription_id' => $subscriptionId,
                        'provider_price_id' => $priceId,
                        'plan_key' => 'unverified_price',
                        'status' => 'unverified_price',
                        'quantity' => 1,
                        'metadata' => ['billing_state' => ['source' => 'analytics_stripe', 'checkout_attempt_id' => $attemptId]],
                    ]);
                    if ($prior !== null && $prior->provider === 'stripe'
                        && $prior->provider_account_key === $accountId
                        && $prior->provider_subscription_id === $subscriptionId
                        && (string) $prior->getKey() !== (string) $unverified->getKey()) {
                        $prior->forceFill(['status' => 'superseded'])->save();
                    }
                    if ($current === null) {
                        CurrentProductSubscription::query()->create([
                            'workspace_id' => $workspaceId,
                            'product' => 'analytics',
                            'product_subscription_id' => $unverified->getKey(),
                        ]);
                    } elseif ((string) $current->product_subscription_id !== (string) $unverified->getKey()) {
                        $current->forceFill(['product_subscription_id' => $unverified->getKey()])->save();
                    }
                }
                $attempt->forceFill([
                    'provider_customer_id' => $customerId,
                    'provider_subscription_id' => $subscriptionId,
                    'status' => 'completed',
                    'completed_at' => now('UTC'),
                ])->save();
                $receipt->forceFill([
                    'workspace_id' => $workspaceId,
                    'ignored_reason' => 'price_not_allowlisted',
                    'metadata' => ['subscription_id' => $subscriptionId],
                ])->save();

                return;
            }

            $existing = $prior !== null
                && $prior->provider === 'stripe'
                && $prior->provider_account_key === $accountId
                && $prior->provider_subscription_id === $subscriptionId
                && $prior->provider_price_id === $priceId
                    ? $prior
                    : null;
            $snapshot = $existing?->metadata['plan_snapshot'] ?? null;
            if (! is_array($snapshot)) {
                $snapshot = $catalogPlan['snapshot'];
            }
            $planKey = $existing !== null && $existing->status !== 'unverified_price'
                ? $existing->plan_key
                : $catalogPlan['key'];
            $values = [
                'workspace_id' => $workspaceId,
                'billing_customer_id' => $customer->getKey(),
                'product' => 'analytics',
                'provider' => 'stripe',
                'provider_account_key' => $accountId,
                'provider_subscription_id' => $subscriptionId,
                'provider_price_id' => $priceId,
                'plan_key' => $planKey,
                'status' => $subscription['status'],
                'quantity' => 1,
                'trial_ends_at' => $this->timestamp($subscription['trial_end'] ?? null),
                'current_period_starts_at' => $this->periodTimestamp($subscription, 'current_period_start'),
                'current_period_ends_at' => $this->periodTimestamp($subscription, 'current_period_end'),
                'cancel_at' => $this->timestamp($subscription['cancel_at'] ?? null),
                'canceled_at' => $this->timestamp($subscription['canceled_at'] ?? null),
                'metadata' => [
                    'plan_snapshot' => $snapshot,
                    'billing_state' => [
                        'source' => 'analytics_stripe',
                        'checkout_attempt_id' => $attemptId,
                        'cancel_at_period_end' => (bool) ($subscription['cancel_at_period_end'] ?? false),
                    ],
                ],
            ];

            $record = $existing ?? ProductSubscription::query()->create($values);
            if ($existing !== null) {
                $record->forceFill($values)->save();
            }

            if ($prior !== null
                && $prior->provider === 'stripe'
                && $prior->provider_account_key === $accountId
                && $prior->provider_subscription_id === $subscriptionId
                && (string) $prior->getKey() !== (string) $record->getKey()) {
                // A portal price change creates a new immutable plan snapshot.
                // Its old price version is history, not another live obligation.
                $prior->forceFill(['status' => 'superseded'])->save();
            }

            // The bound provider checkout completed, even if its first
            // reconciled subscription state is already canceled or unpaid.
            // Never silently restore the unlimited legacy slot afterward.
            if ($current === null) {
                CurrentProductSubscription::query()->create([
                    'workspace_id' => $workspaceId,
                    'product' => 'analytics',
                    'product_subscription_id' => $record->getKey(),
                ]);
            } elseif ($current !== null
                && (string) $current->product_subscription_id !== (string) $record->getKey()) {
                $current->forceFill(['product_subscription_id' => $record->getKey()])->save();
            }

            $attempt->forceFill([
                'provider_customer_id' => $customerId,
                'provider_subscription_id' => $subscriptionId,
                'status' => 'completed',
                'completed_at' => now('UTC'),
            ])->save();
            $receipt->forceFill([
                'workspace_id' => $workspaceId,
                'processing_status' => 'applied',
                'ignored_reason' => null,
                'processed_at' => now('UTC'),
                'metadata' => ['subscription_id' => $subscriptionId, 'price_id' => $priceId],
            ])->save();
            $reconciled = true;
        });

        // Unknown prices leave a retryable receipt. A previously paid current slot
        // is suspended above, so it cannot keep an old allowance after a price swap.
        return $reconciled;
    }

    /** @param array<string, mixed> $subscription */
    private function priceId(array $subscription): string
    {
        $items = data_get($subscription, 'items.data');
        if (! is_array($items) || count($items) !== 1 || ! is_array($items[0])) {
            throw new AnalyticsBillingReconciliationFailure('subscription_items_invalid');
        }

        $price = $items[0]['price'] ?? null;
        $id = is_array($price) ? ($price['id'] ?? null) : $price;
        if (! is_string($id) || ! preg_match('/^price_[A-Za-z0-9]+$/', $id)
            || (int) ($items[0]['quantity'] ?? 0) !== 1) {
            throw new AnalyticsBillingReconciliationFailure('subscription_price_invalid');
        }

        return $id;
    }

    /** @param array<string, mixed> $subscription */
    private function periodTimestamp(array $subscription, string $field): ?CarbonImmutable
    {
        return $this->timestamp($subscription[$field] ?? data_get($subscription, 'items.data.0.'.$field));
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        return is_int($value) && $value > 0 ? CarbonImmutable::createFromTimestampUTC($value) : null;
    }

    private function expectedLiveMode(): bool
    {
        $secret = config('analytics.billing.stripe.secret');
        if (! is_string($secret) || ! preg_match('/^sk_(live|test)_[A-Za-z0-9]+$/', $secret, $matches)) {
            throw new RuntimeException('analytics_provider_key_mode_unverified');
        }

        return $matches[1] === 'live';
    }

    /** @param array<string, mixed> $terms */
    private function validStoredTermsHash(array $terms, string $hash): bool
    {
        try {
            return preg_match('/^[a-f0-9]{64}$/', $hash) === 1
                && hash_equals($hash, $this->catalog->storedTermsHash($terms));
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $snapshot */
    private function validSnapshot(array $snapshot): bool
    {
        if (! is_string($snapshot['name'] ?? null) || trim($snapshot['name']) === ''
            || ! is_array($snapshot['entitlements'] ?? null)
            || ! array_is_list($snapshot['entitlements'])
            || $snapshot['entitlements'] === []
            || ! is_array($snapshot['limits'] ?? null)) {
            return false;
        }
        foreach ($snapshot['entitlements'] as $entitlement) {
            if (! is_string($entitlement) || trim($entitlement) === '' || $entitlement === '*') {
                return false;
            }
        }
        if (count(array_unique($snapshot['entitlements'])) !== count($snapshot['entitlements'])) {
            return false;
        }
        foreach ($snapshot['limits'] as $key => $value) {
            if (! is_string($key) || trim($key) === ''
                || (! is_int($value) && $value !== null)
                || (is_int($value) && $value < 0)) {
                return false;
            }
        }
        foreach (['sites', 'members', 'events_per_month', 'retention_days', 'aggregate_retention_months', 'export_retention_hours'] as $key) {
            $value = $snapshot['limits'][$key] ?? null;
            if (! array_key_exists($key, $snapshot['limits']) || (! is_int($value) && $value !== null) || (is_int($value) && $value < 0)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $terms */
    private function priceMatchesTerms(string $priceId, array $terms): bool
    {
        if (($terms['price_id'] ?? null) !== $priceId) {
            return false;
        }
        try {
            $this->catalog->storedTermsHash($terms);
        } catch (Throwable) {
            return false;
        }
        $price = $this->stripe->retrievePrice($priceId);
        $recurring = $price['recurring'] ?? null;

        // Stripe can archive a price after checkout without changing the
        // already purchased subscription's fixed billing terms.
        return ($price['id'] ?? null) === $priceId
            && is_bool($price['active'] ?? null)
            && ($price['livemode'] ?? null) === $this->expectedLiveMode()
            && ($price['type'] ?? null) === 'recurring'
            && ($price['billing_scheme'] ?? null) === 'per_unit'
            && ($price['transform_quantity'] ?? null) === null
            && ($price['unit_amount'] ?? null) === $terms['amount']
            && ($price['currency'] ?? null) === $terms['currency']
            && is_array($recurring)
            && ($recurring['interval'] ?? null) === $terms['interval']
            && ($recurring['interval_count'] ?? null) === $terms['interval_count']
            && ($recurring['usage_type'] ?? null) === 'licensed';
    }
}

final class AnalyticsBillingReconciliationFailure extends RuntimeException {}
