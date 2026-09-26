<?php

namespace App\Modules\Analytics\Services\Billing;

use App\Core\Enums\ProductKey;
use App\Core\Models\AnalyticsCheckoutAttempt;
use App\Core\Models\BillingCustomer;
use App\Core\Models\CurrentProductSubscription;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProductSubscription;
use App\Core\Models\Workspace as CoreWorkspace;
use App\Modules\Analytics\Models\Workspace as AnalyticsWorkspace;
use App\Modules\Analytics\Services\AnalyticsPlanAuthority;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Starts Analytics purchases and manages only the verified Analytics Core subscription slot. */
final class AnalyticsBillingCheckout
{
    private const PRODUCT = 'analytics';

    public function __construct(
        private readonly AnalyticsBillingCatalog $catalog,
        private readonly AnalyticsBillingAccess $access,
        private readonly AnalyticsStripeClient $stripe,
        private readonly AnalyticsBillingReturnTarget $returnTargets,
        private readonly AnalyticsPlanAuthority $planAuthority,
    ) {}

    public function purchaseAvailable(
        AnalyticsWorkspace $workspace,
        CoreWorkspace $coreWorkspace,
        string $providerAccountKey,
    ): bool {
        if (! (bool) config('analytics.billing.enabled', false)
            || ! (bool) config('analytics.billing.webhooks_enabled', false)
            || config('analytics.plan_authority', 'legacy') !== 'core'
            || $this->catalog->plans() === []) {
            return false;
        }

        try {
            $this->assertClientConfigured();
            $this->assertWebhookPathConfigured();

            if ($providerAccountKey === '' || $providerAccountKey !== $this->stripe->configuredAccountId()) {
                return false;
            }

            $this->assertCurrentCorePlan($workspace, $coreWorkspace, allowCanceledPaidSlot: true);
            $this->assertPurchaseSlotAvailable($workspace, $coreWorkspace, $providerAccountKey);

            return true;
        } catch (AnalyticsBillingException|AuthorizationException) {
            return false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{id:string,url:string,status:string,expires_at:int,reused:bool}
     */
    public function start(
        AnalyticsWorkspace $workspace,
        PlatformUser $actor,
        string $planKey,
        string $idempotencyKey,
        string $successUrl,
        string $cancelUrl,
    ): array {
        if (! (bool) config('analytics.billing.enabled', false)
            || ! (bool) config('analytics.billing.webhooks_enabled', false)
            || config('analytics.plan_authority', 'legacy') !== 'core') {
            throw new AnalyticsBillingException('Analytics paid checkout is not enabled.');
        }

        $context = $this->access->authorize($workspace, $actor);
        $this->assertClientConfigured();
        $this->assertWebhookPathConfigured();
        $this->assertRequestKey($idempotencyKey);

        $plan = $this->catalog->forKey($planKey);
        if ($plan === null) {
            throw new AnalyticsBillingException('The selected Analytics plan is unavailable.');
        }

        $this->assertCurrentCorePlan($workspace, $context->coreWorkspace, allowCanceledPaidSlot: true);
        $this->returnTargets->assertAllowed($successUrl, $workspace, 'success');
        $this->returnTargets->assertAllowed($cancelUrl, $workspace, 'cancel');
        $this->assertPriceMatchesCatalog($plan);
        $customerId = $this->assertPurchaseSlotAvailable($workspace, $context->coreWorkspace, $context->providerAccountKey);
        $priceTerms = $this->catalog->priceTerms($plan);
        $priceTermsHash = $this->catalog->termsHash($plan);

        $requestFingerprint = hash('sha256', json_encode([
            'plan_key' => $plan['key'],
            'plan_snapshot' => $plan['snapshot'],
            'price_terms' => $priceTerms,
            'price_terms_hash' => $priceTermsHash,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $keyHash = hash('sha256', $idempotencyKey);

        $attempt = $this->reserveAttempt(
            $context->coreWorkspace,
            $workspace,
            $context->providerAccountKey,
            $plan,
            $plan['snapshot'],
            $priceTerms,
            $priceTermsHash,
            $keyHash,
            $requestFingerprint,
            (string) $context->actor->getKey(),
            $customerId,
        );

        if ($attempt->provider_checkout_session_id !== null) {
            return $this->existingSession($attempt, reused: true);
        }
        // Stripe may discard idempotency records after 24 hours. A provider
        // response lost beyond this conservative window cannot be replayed
        // safely; keep the attempt exclusive for manual reconciliation.
        if ($attempt->created_at === null || $attempt->created_at->lte(now('UTC')->subHours(12))) {
            throw new AnalyticsBillingException('This unresolved Analytics checkout needs provider reconciliation before retrying.');
        }

        // Authorization may change while the request is preparing the provider call.
        $context = $this->access->authorize($workspace, $actor);
        $this->assertCurrentCorePlan($workspace, $context->coreWorkspace, allowCanceledPaidSlot: true);
        $customerId = $this->assertPurchaseSlotAvailable($workspace, $context->coreWorkspace, $context->providerAccountKey);
        if ($customerId !== $attempt->provider_customer_id) {
            throw new AnalyticsBillingException('The Analytics billing customer changed during checkout preparation.');
        }
        if ($context->providerAccountKey === '' || $context->providerAccountKey !== $attempt->provider_account_key) {
            throw new AuthorizationException('Analytics billing account access changed during checkout.');
        }

        $metadata = [
            'product' => self::PRODUCT,
            'core_workspace_id' => (string) $context->coreWorkspace->getKey(),
            'analytics_workspace_id' => (string) $workspace->getKey(),
            'provider_account_key' => $context->providerAccountKey,
            'checkout_attempt_id' => (string) $attempt->getKey(),
        ];
        $parameters = [
            'mode' => 'subscription',
            'line_items' => [[
                'price' => $plan['price_id'],
                'quantity' => 1,
            ]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $attempt->getKey(),
            'metadata' => $metadata,
            'subscription_data' => ['metadata' => $metadata],
        ];
        if ($customerId !== null) {
            $parameters['customer'] = $customerId;
        }

        try {
            $session = $this->stripe->createCheckoutSession(
                $parameters,
                $this->providerIdempotencyKey('checkout', (string) $attempt->getKey()),
            );
            $this->recordSession($attempt, $session);
            if ($session['status'] !== 'open'
                || $session['expires_at'] === null
                || $session['expires_at'] <= now()->timestamp) {
                throw new AnalyticsBillingException('Stripe did not return an active Analytics checkout session.');
            }
        } catch (Throwable $exception) {
            $this->recordFailure($attempt, 'provider_error');

            throw $exception;
        }

        return [
            'id' => $session['id'],
            'url' => $session['url'],
            'status' => 'open',
            'expires_at' => $session['expires_at'],
            'reused' => false,
        ];
    }

    /** @return array{id:string,url:string} */
    public function portal(
        AnalyticsWorkspace $workspace,
        PlatformUser $actor,
        string $returnUrl,
        string $idempotencyKey,
    ): array {
        if (! (bool) config('analytics.billing.webhooks_enabled', false)) {
            throw new AnalyticsBillingException('Analytics billing management is not enabled.');
        }

        $context = $this->access->authorize($workspace, $actor);
        $this->assertClientConfigured();
        $this->assertWebhookPathConfigured();
        $this->assertRequestKey($idempotencyKey);
        $this->returnTargets->assertAllowed($returnUrl, $workspace, 'portal');
        $billing = $this->boundCurrentSubscription($context->coreWorkspace, $context->providerAccountKey);

        // Re-read both the Core billing role and product grant immediately before use.
        $context = $this->access->authorize($workspace, $actor);
        if ($context->providerAccountKey !== $billing['subscription']->provider_account_key) {
            throw new AuthorizationException('Analytics billing account access changed.');
        }

        return $this->stripe->createPortalSession(
            $billing['customer']->provider_customer_id,
            $returnUrl,
            $this->providerIdempotencyKey('portal', (string) $context->coreWorkspace->getKey(), $idempotencyKey),
        );
    }

    /** @return array<string, mixed> */
    public function cancel(
        AnalyticsWorkspace $workspace,
        PlatformUser $actor,
        bool $atPeriodEnd,
        string $idempotencyKey,
    ): array {
        if (! (bool) config('analytics.billing.webhooks_enabled', false)) {
            throw new AnalyticsBillingException('Analytics billing management is not enabled.');
        }

        $context = $this->access->authorize($workspace, $actor);
        $this->assertClientConfigured();
        $this->assertWebhookPathConfigured();
        $this->assertRequestKey($idempotencyKey);
        $billing = $this->boundCurrentSubscription($context->coreWorkspace, $context->providerAccountKey);
        $subscription = $billing['subscription'];

        if (! in_array($subscription->status, ['active', 'trialing', 'past_due'], true)) {
            throw new AnalyticsBillingException('The current Analytics subscription cannot be canceled.');
        }

        // Re-read authorization and subscription binding immediately before use.
        $context = $this->access->authorize($workspace, $actor);
        $latest = $this->boundCurrentSubscription($context->coreWorkspace, $context->providerAccountKey);
        if ((string) $latest['subscription']->getKey() !== (string) $subscription->getKey()) {
            throw new AuthorizationException('The current Analytics subscription changed during cancellation.');
        }

        return $this->stripe->cancelSubscription(
            (string) $subscription->provider_subscription_id,
            $atPeriodEnd,
            $this->providerIdempotencyKey(
                'cancel',
                (string) $context->coreWorkspace->getKey(),
                (string) $subscription->provider_subscription_id,
                $atPeriodEnd ? 'period_end' : 'immediate',
                $idempotencyKey,
            ),
        );
    }

    /** @param array<string, mixed> $plan */
    public function assertPriceMatchesCatalog(array $plan): void
    {
        $price = $this->stripe->retrievePrice($plan['price_id']);
        $recurring = $price['recurring'] ?? null;

        if (($price['active'] ?? false) !== true
            || ($price['livemode'] ?? null) !== $this->stripe->liveMode()
            || ($price['type'] ?? null) !== 'recurring'
            || ($price['billing_scheme'] ?? null) !== 'per_unit'
            || ($price['transform_quantity'] ?? null) !== null
            || ! is_int($price['unit_amount'] ?? null)
            || $price['unit_amount'] !== $plan['amount']
            || ! is_string($price['currency'] ?? null)
            || strtolower($price['currency']) !== $plan['currency']
            || ! is_array($recurring)
            || ($recurring['interval'] ?? null) !== $plan['interval']
            || ($recurring['interval_count'] ?? null) !== $plan['interval_count']
            || ($recurring['usage_type'] ?? null) !== 'licensed') {
            throw new AnalyticsBillingException('The configured Analytics price does not match its approved plan terms.');
        }
    }

    private function assertClientConfigured(): void
    {
        if (! $this->stripe->configured() || $this->stripe->configuredAccountId() === null) {
            throw new AnalyticsBillingException('Analytics Stripe billing is not configured.');
        }
    }

    private function assertCurrentCorePlan(
        AnalyticsWorkspace $analyticsWorkspace,
        CoreWorkspace $coreWorkspace,
        bool $allowCanceledPaidSlot = false,
    ): void {
        $resolution = $this->planAuthority->resolve($analyticsWorkspace);
        $current = CurrentProductSubscription::query()
            ->with('subscription')
            ->where('workspace_id', $coreWorkspace->getKey())
            ->where('product', self::PRODUCT)
            ->first();
        $subscription = $current?->subscription;
        $metadata = $subscription?->metadata;
        $storedSnapshot = is_array($metadata) ? ($metadata['plan_snapshot'] ?? null) : null;
        $terminalPaidRenewal = $allowCanceledPaidSlot
            && ! $resolution->available
            && $resolution->unavailableReason === 'subscription_status_not_entitled'
            && in_array($resolution->subscriptionStatus, ['canceled', 'incomplete_expired'], true)
            && $current instanceof CurrentProductSubscription
            && $subscription instanceof ProductSubscription
            && $subscription->provider === 'stripe'
            && $subscription->plan_key !== 'legacy_access'
            && in_array($subscription->status, ['canceled', 'incomplete_expired'], true)
            && is_array($storedSnapshot);
        $snapshot = $terminalPaidRenewal ? $storedSnapshot : $resolution->snapshot;
        $planKey = $terminalPaidRenewal ? $subscription->plan_key : $resolution->planKey;
        $planName = $terminalPaidRenewal ? ($snapshot['name'] ?? null) : $resolution->planName;
        $entitlements = $snapshot['entitlements'] ?? null;
        $limits = $snapshot['limits'] ?? null;
        $requiredLimits = [
            'sites',
            'members',
            'events_per_month',
            'retention_days',
            'aggregate_retention_months',
            'export_retention_hours',
        ];

        if ((! $resolution->available && ! $terminalPaidRenewal)
            || $resolution->product !== ProductKey::Analytics
            || $resolution->workspaceId !== (string) $coreWorkspace->getKey()
            || ! is_string($planKey)
            || $planKey === ''
            || ! is_string($planName)
            || trim($planName) === ''
            || ! is_array($entitlements)
            || ! array_is_list($entitlements)
            || (! $terminalPaidRenewal && $resolution->entitlements !== $entitlements)
            || ! is_array($limits)
            || (! $terminalPaidRenewal && $resolution->limits !== $limits)
            || ! is_string($snapshot['name'] ?? null)
            || trim($snapshot['name']) === ''
            || $snapshot['name'] !== trim($snapshot['name'])
            || $snapshot['name'] !== $planName
            || (array_key_exists('description', $snapshot) && ! is_string($snapshot['description']))) {
            throw new AnalyticsBillingException('The current Core Analytics plan could not be confirmed.');
        }

        foreach ($entitlements as $entitlement) {
            if (! is_string($entitlement) || trim($entitlement) === '') {
                throw new AnalyticsBillingException('The current Core Analytics plan snapshot is invalid.');
            }
        }
        if (count(array_unique($entitlements)) !== count($entitlements)) {
            throw new AnalyticsBillingException('The current Core Analytics plan snapshot is invalid.');
        }
        if ($planKey !== 'legacy_access' && $entitlements === []) {
            throw new AnalyticsBillingException('The current Core Analytics plan has no approved entitlements.');
        }

        foreach ($requiredLimits as $requiredLimit) {
            if (! array_key_exists($requiredLimit, $limits)) {
                throw new AnalyticsBillingException('The current Core Analytics plan snapshot is incomplete.');
            }
        }
        foreach ($limits as $limit => $value) {
            if (! is_string($limit) || $limit === ''
                || (! is_int($value) && $value !== null)
                || (is_int($value) && $value < 0)) {
                throw new AnalyticsBillingException('The current Core Analytics plan snapshot is invalid.');
            }
        }

        if (! $current instanceof CurrentProductSubscription
            || (string) $current->workspace_id !== (string) $coreWorkspace->getKey()
            || $current->product !== self::PRODUCT
            || ! $subscription instanceof ProductSubscription
            || (string) $subscription->workspace_id !== (string) $coreWorkspace->getKey()
            || $subscription->product !== self::PRODUCT
            || $subscription->plan_key !== $planKey
            || $storedSnapshot !== $snapshot) {
            throw new AnalyticsBillingException('The current Core Analytics plan binding is invalid.');
        }

        $isLegacyBaseline = $planKey === 'legacy_access';
        if (in_array('*', $entitlements, true)) {
            if (! $isLegacyBaseline
                || $entitlements !== ['*']
                || $subscription->provider !== 'legacy_access'
                || $subscription->status !== 'active'
                || ($metadata['entitlement_snapshot'] ?? null) !== true
                || data_get($metadata, 'billing_state.kind') !== 'no_charge_legacy_access_baseline') {
                throw new AnalyticsBillingException('The current Core Analytics plan snapshot is invalid.');
            }
        } elseif ($isLegacyBaseline || $subscription->provider === 'legacy_access') {
            throw new AnalyticsBillingException('The current Core Analytics baseline plan is invalid.');
        }
    }

    private function assertWebhookPathConfigured(): void
    {
        $webhookSecret = config('analytics.billing.stripe.webhook_secret');
        if (! (bool) config('analytics.billing.webhooks_enabled', false)
            || ! is_string($webhookSecret)
            || ! preg_match('/^whsec_[A-Za-z0-9]+$/', $webhookSecret)) {
            throw new AnalyticsBillingException('Analytics signed billing webhooks are not configured.');
        }
    }

    private function assertRequestKey(string $idempotencyKey): void
    {
        if (strlen($idempotencyKey) < 16 || strlen($idempotencyKey) > 200
            || ! preg_match('/^[A-Za-z0-9._:-]+$/', $idempotencyKey)) {
            throw new AnalyticsBillingException('The Analytics billing request key is invalid.');
        }
    }

    /** @param array<string, mixed> $plan */
    private function reserveAttempt(
        CoreWorkspace $coreWorkspace,
        AnalyticsWorkspace $analyticsWorkspace,
        string $accountKey,
        array $plan,
        array $planSnapshot,
        array $priceTerms,
        string $priceTermsHash,
        string $keyHash,
        string $requestFingerprint,
        string $initiatedByUserId,
        ?string $providerCustomerId,
    ): AnalyticsCheckoutAttempt {
        return DB::connection('core')->transaction(function () use (
            $coreWorkspace,
            $analyticsWorkspace,
            $accountKey,
            $plan,
            $planSnapshot,
            $priceTerms,
            $priceTermsHash,
            $keyHash,
            $requestFingerprint,
            $initiatedByUserId,
            $providerCustomerId,
        ): AnalyticsCheckoutAttempt {
            // SQLite starts deferred transactions. This targeted write acquires its
            // database-wide write reservation before any attempt or plan-slot reads.
            $fenced = CoreWorkspace::query()
                ->whereKey($coreWorkspace->getKey())
                ->update(['updated_at' => now()]);
            if ($fenced !== 1) {
                throw new AuthorizationException('The Analytics workspace could not be locked for billing.');
            }

            $lockedWorkspace = CoreWorkspace::query()
                ->whereKey($coreWorkspace->getKey())
                ->where('status', 'active')
                ->whereNull('archived_at')
                ->lockForUpdate()
                ->first();
            if (! $lockedWorkspace instanceof CoreWorkspace) {
                throw new AuthorizationException('The Analytics workspace is not available for billing.');
            }

            $this->assertCurrentCorePlan($analyticsWorkspace, $lockedWorkspace, allowCanceledPaidSlot: $providerCustomerId !== null);

            $matching = AnalyticsCheckoutAttempt::query()
                ->where('provider_account_key', $accountKey)
                ->where('core_workspace_id', $lockedWorkspace->getKey())
                ->where('idempotency_key_hash', $keyHash)
                ->lockForUpdate()
                ->first();

            if ($matching instanceof AnalyticsCheckoutAttempt) {
                if ($matching->initiated_by_user_id !== $initiatedByUserId) {
                    throw new AnalyticsBillingException('This Analytics checkout request belongs to another workspace manager.');
                }

                if ($matching->request_fingerprint !== $requestFingerprint) {
                    throw new AnalyticsBillingException('This Analytics billing request key was already used for different checkout terms.');
                }

                if (! $this->sameJsonValue($matching->plan_snapshot ?? [], $planSnapshot)
                    || $matching->price_terms_hash !== $priceTermsHash
                    || $this->catalog->storedTermsHash($matching->price_terms ?? []) !== $priceTermsHash) {
                    throw new AnalyticsBillingException('The stored Analytics checkout approval is invalid.');
                }

                if ($providerCustomerId !== null
                    && $matching->provider_customer_id !== null
                    && $matching->provider_customer_id !== $providerCustomerId) {
                    throw new AnalyticsBillingException('The Analytics checkout attempt is bound to another Stripe customer.');
                }
                if ($providerCustomerId !== null && $matching->provider_customer_id === null) {
                    $matching->forceFill(['provider_customer_id' => $providerCustomerId])->save();
                }
            }

            $activeAttempts = AnalyticsCheckoutAttempt::query()
                ->where('provider_account_key', $accountKey)
                ->where('core_workspace_id', $lockedWorkspace->getKey())
                ->whereIn('status', ['pending', 'open', 'failed', 'completed'])
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->get()
                ->filter(fn (AnalyticsCheckoutAttempt $candidate): bool => $candidate->status !== 'completed'
                    || ! $this->hasProjectedSubscription($candidate));

            if ($activeAttempts->count() > 1) {
                throw new AnalyticsBillingException('More than one Analytics checkout is awaiting reconciliation for this workspace.');
            }

            $activeAttempt = $activeAttempts->first();

            if ($activeAttempt instanceof AnalyticsCheckoutAttempt) {
                if ($activeAttempt->status === 'failed') {
                    if ($activeAttempt->initiated_by_user_id !== $initiatedByUserId
                        || $activeAttempt->request_fingerprint !== $requestFingerprint
                        || ! $this->sameJsonValue($activeAttempt->plan_snapshot ?? [], $planSnapshot)
                        || $this->catalog->storedTermsHash($activeAttempt->price_terms ?? []) !== $priceTermsHash
                        || $activeAttempt->price_terms_hash !== $priceTermsHash) {
                        throw new AnalyticsBillingException('The unresolved Analytics checkout must be resumed by its initiator with the same approved terms.');
                    }

                    return $activeAttempt;
                }

                if ($activeAttempt->status === 'completed') {
                    throw new AnalyticsBillingException('The completed Analytics checkout is awaiting signed subscription reconciliation.');
                }

                if ($activeAttempt->initiated_by_user_id !== $initiatedByUserId) {
                    throw new AnalyticsBillingException('An Analytics checkout is already pending for this workspace manager.');
                }

                if ($activeAttempt->request_fingerprint !== $requestFingerprint) {
                    throw new AnalyticsBillingException('An Analytics checkout is already pending for this workspace.');
                }

                if (! $this->sameJsonValue($activeAttempt->plan_snapshot ?? [], $planSnapshot)
                    || $this->catalog->storedTermsHash($activeAttempt->price_terms ?? []) !== $priceTermsHash
                    || $activeAttempt->price_terms_hash !== $priceTermsHash) {
                    throw new AnalyticsBillingException('The pending Analytics checkout approval is invalid.');
                }

                return $activeAttempt;
            }

            if ($matching instanceof AnalyticsCheckoutAttempt) {
                if ($matching->status === 'failed' && $matching->provider_checkout_session_id === null) {
                    return $matching;
                }

                if ($matching->provider_checkout_session_id !== null) {
                    return $matching;
                }

                throw new AnalyticsBillingException('The stored Analytics checkout attempt cannot be resumed safely.');
            }

            return AnalyticsCheckoutAttempt::query()->create([
                'core_workspace_id' => (string) $lockedWorkspace->getKey(),
                'analytics_workspace_id' => (string) $analyticsWorkspace->getKey(),
                'initiated_by_user_id' => $initiatedByUserId,
                'provider_account_key' => $accountKey,
                'plan_key' => $plan['key'],
                'provider_price_id' => $plan['price_id'],
                'plan_snapshot' => $planSnapshot,
                'price_terms' => $priceTerms,
                'price_terms_hash' => $priceTermsHash,
                'idempotency_key_hash' => $keyHash,
                'request_fingerprint' => $requestFingerprint,
                'provider_customer_id' => $providerCustomerId,
                'status' => 'pending',
            ]);
        }, attempts: 3);
    }

    private function hasProjectedSubscription(AnalyticsCheckoutAttempt $attempt): bool
    {
        if (! is_string($attempt->provider_subscription_id) || $attempt->provider_subscription_id === '') {
            return false;
        }

        $subscriptions = ProductSubscription::query()
            ->where('workspace_id', $attempt->core_workspace_id)
            ->where('product', self::PRODUCT)
            ->where('provider', 'stripe')
            ->where('provider_account_key', $attempt->provider_account_key)
            ->where('provider_subscription_id', $attempt->provider_subscription_id)
            ->get();

        if ($subscriptions->count() !== 1
            || data_get($subscriptions->first()->metadata, 'billing_state.checkout_attempt_id') !== (string) $attempt->getKey()) {
            return false;
        }

        $current = CurrentProductSubscription::query()
            ->with('subscription')
            ->where('workspace_id', $attempt->core_workspace_id)
            ->where('product', self::PRODUCT)
            ->first();

        if (! $current instanceof CurrentProductSubscription) {
            return false;
        }
        if ((string) $current->product_subscription_id === (string) $subscriptions->first()->getKey()) {
            return true;
        }

        // A prior completed checkout may have been replaced by a later, fully
        // projected renewal. The current Stripe slot must prove that later
        // completion; a legacy pointer cannot settle a recovered paid attempt.
        $currentSubscription = $current->subscription;
        if (! $currentSubscription instanceof ProductSubscription
            || $currentSubscription->provider !== 'stripe'
            || $currentSubscription->provider_account_key !== $attempt->provider_account_key
            || (string) $currentSubscription->workspace_id !== (string) $attempt->core_workspace_id
            || ! is_string($currentSubscription->provider_subscription_id)) {
            return false;
        }
        $newerAttemptId = data_get($currentSubscription->metadata, 'billing_state.checkout_attempt_id');
        $newerAttempt = is_string($newerAttemptId) ? AnalyticsCheckoutAttempt::query()->find($newerAttemptId) : null;

        return $newerAttempt instanceof AnalyticsCheckoutAttempt
            && $newerAttempt->status === 'completed'
            && (string) $newerAttempt->core_workspace_id === (string) $attempt->core_workspace_id
            && (string) $newerAttempt->analytics_workspace_id === (string) $attempt->analytics_workspace_id
            && $newerAttempt->provider_account_key === $attempt->provider_account_key
            && $newerAttempt->provider_subscription_id === $currentSubscription->provider_subscription_id
            && $newerAttempt->created_at !== null
            && $attempt->created_at !== null
            && $newerAttempt->created_at->gt($attempt->created_at);
    }

    /** @param array{id:string,url:string,status:?string,expires_at:?int,customer_id:?string,mode:?string,client_reference_id:?string,metadata:?array<string,mixed>} $session */
    private function recordSession(AnalyticsCheckoutAttempt $attempt, array $session): void
    {
        DB::connection('core')->transaction(function () use ($attempt, $session): void {
            $fresh = AnalyticsCheckoutAttempt::query()->whereKey($attempt->getKey())->lockForUpdate()->first();
            if (! $fresh instanceof AnalyticsCheckoutAttempt) {
                throw new AnalyticsBillingException('The Analytics checkout attempt could not be recorded.');
            }

            if ($fresh->provider_checkout_session_id !== null
                && $fresh->provider_checkout_session_id !== $session['id']) {
                throw new AnalyticsBillingException('Stripe returned a conflicting Analytics checkout session.');
            }
            if ($fresh->provider_customer_id !== null
                && $fresh->provider_customer_id !== $session['customer_id']) {
                throw new AnalyticsBillingException('Stripe returned a Checkout Session for another Analytics customer.');
            }

            $status = match ($session['status']) {
                'open' => 'open',
                'complete' => 'completed',
                // The create response does not prove that an expired session has
                // no subscription. Keep it exclusive until a full retrieval does.
                'expired' => 'open',
                default => $fresh->status === 'completed' ? 'completed' : 'open',
            };
            $fresh->forceFill([
                'provider_checkout_session_id' => $session['id'],
                'provider_customer_id' => $session['customer_id'] ?? $fresh->provider_customer_id,
                'status' => in_array($fresh->status, ['completed', 'expired'], true) ? $fresh->status : $status,
                'completed_at' => $status === 'completed' ? ($fresh->completed_at ?? now()) : $fresh->completed_at,
                'expires_at' => $session['expires_at'] === null
                    ? $fresh->expires_at
                    : Carbon::createFromTimestamp($session['expires_at']),
                'failure_code' => null,
            ])->save();
        }, attempts: 3);
    }

    private function recordFailure(AnalyticsCheckoutAttempt $attempt, string $failureCode): void
    {
        AnalyticsCheckoutAttempt::query()
            ->whereKey($attempt->getKey())
            ->whereNull('provider_checkout_session_id')
            ->whereNotIn('status', ['completed', 'expired'])
            ->update(['status' => 'failed', 'failure_code' => $failureCode, 'updated_at' => now()]);
    }

    /** @return array{id:string,url:string,status:string,expires_at:int,reused:bool} */
    private function existingSession(AnalyticsCheckoutAttempt $attempt, bool $reused): array
    {
        $session = $this->stripe->retrieveCheckoutSession((string) $attempt->provider_checkout_session_id);
        $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
        foreach ([
            'product' => self::PRODUCT,
            'core_workspace_id' => (string) $attempt->core_workspace_id,
            'analytics_workspace_id' => (string) $attempt->analytics_workspace_id,
            'provider_account_key' => (string) $attempt->provider_account_key,
            'checkout_attempt_id' => (string) $attempt->getKey(),
        ] as $key => $value) {
            if (($metadata[$key] ?? null) !== $value) {
                throw new AnalyticsBillingException('The stored Analytics checkout session binding is invalid.');
            }
        }

        if (($session['mode'] ?? null) !== 'subscription'
            || ($session['client_reference_id'] ?? null) !== (string) $attempt->getKey()
            || ($attempt->provider_customer_id !== null
                && $this->providerReferenceId($session['customer'] ?? null) !== $attempt->provider_customer_id)) {
            throw new AnalyticsBillingException('The stored Analytics checkout session binding is invalid.');
        }

        $status = is_string($session['status'] ?? null) ? $session['status'] : null;
        $url = $session['url'] ?? null;
        $expiresAt = is_int($session['expires_at'] ?? null) ? $session['expires_at'] : null;

        if ($status !== 'open'
            || $expiresAt === null
            || $expiresAt <= now()->timestamp
            || ! is_string($url)
            || ! $this->isCheckoutUrl($url)) {
            $confirmedExpired = $status === 'expired'
                && $attempt->provider_subscription_id === null
                && array_key_exists('subscription', $session)
                && $session['subscription'] === null;
            $attempt->forceFill([
                'status' => $status === 'complete' ? 'completed' : ($confirmedExpired ? 'expired' : $attempt->status),
                'completed_at' => $status === 'complete' ? ($attempt->completed_at ?? now()) : $attempt->completed_at,
                'expires_at' => $expiresAt === null ? $attempt->expires_at : Carbon::createFromTimestamp($expiresAt),
            ])->save();

            throw new AnalyticsBillingException('The Analytics checkout session is no longer open. Refresh billing to start a new checkout.');
        }

        return [
            'id' => (string) $attempt->provider_checkout_session_id,
            'url' => $url,
            'status' => 'open',
            'expires_at' => $expiresAt,
            'reused' => $reused,
        ];
    }

    private function isCheckoutUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && is_string($parts['host'] ?? null)
            && strtolower($parts['host']) === 'checkout.stripe.com'
            && (! isset($parts['port']) || (int) $parts['port'] === 443)
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }

    private function assertPurchaseSlotAvailable(
        AnalyticsWorkspace $analyticsWorkspace,
        CoreWorkspace $workspace,
        string $accountKey,
    ): ?string {
        $current = CurrentProductSubscription::query()
            ->with('subscription.billingCustomer')
            ->where('workspace_id', $workspace->getKey())
            ->where('product', self::PRODUCT)
            ->first();

        if (! $current instanceof CurrentProductSubscription
            || (string) $current->workspace_id !== (string) $workspace->getKey()
            || $current->product !== self::PRODUCT
            || ! $current->subscription instanceof ProductSubscription
            || (string) $current->subscription->workspace_id !== (string) $workspace->getKey()
            || $current->subscription->product !== self::PRODUCT) {
            throw new AnalyticsBillingException('The current Analytics billing slot is unavailable.');
        }

        $subscription = $current->subscription;
        if ($subscription->provider === 'legacy_access'
            && $subscription->plan_key === 'legacy_access'
            && $subscription->billing_customer_id === null
            && $subscription->provider_subscription_id === null
            && $subscription->provider_price_id === null) {
            return null;
        }

        if ($subscription->provider !== 'stripe' || $subscription->provider_account_key !== $accountKey) {
            throw new AnalyticsBillingException('The current Analytics billing slot has an unresolved provider binding.');
        }

        if (ProductSubscription::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('product', self::PRODUCT)
            ->where('provider', 'stripe')
            ->where('provider_account_key', $accountKey)
            ->whereIn('status', ['active', 'trialing', 'past_due', 'unpaid', 'incomplete', 'paused'])
            ->exists()) {
            throw new AnalyticsBillingException('This Analytics workspace already has a Stripe subscription.');
        }

        if (! in_array($subscription->status, ['canceled', 'incomplete_expired'], true)) {
            throw new AnalyticsBillingException('The current Analytics subscription must be managed before starting another checkout.');
        }

        $customer = $this->validatedCustomerForSubscription($subscription, $workspace, $accountKey);
        $this->assertProviderConfirmedTerminalSubscription($analyticsWorkspace, $workspace, $accountKey, $subscription, $customer);

        return $customer->provider_customer_id;
    }

    private function assertProviderConfirmedTerminalSubscription(
        AnalyticsWorkspace $analyticsWorkspace,
        CoreWorkspace $workspace,
        string $accountKey,
        ProductSubscription $subscription,
        BillingCustomer $customer,
    ): void {
        $subscriptionId = $subscription->provider_subscription_id;
        if (! is_string($subscriptionId) || $subscriptionId === '') {
            throw new AnalyticsBillingException('The canceled Analytics subscription has no provider identity.');
        }

        $attempt = AnalyticsCheckoutAttempt::query()
            ->where('provider_account_key', $accountKey)
            ->where('core_workspace_id', $workspace->getKey())
            ->where('analytics_workspace_id', $analyticsWorkspace->getKey())
            ->where('provider_subscription_id', $subscriptionId)
            ->first();
        $provider = $this->stripe->retrieveSubscription($subscriptionId);
        $metadata = is_array($provider['metadata'] ?? null) ? $provider['metadata'] : [];
        $providerCustomerId = $this->providerReferenceId($provider['customer'] ?? null);
        $items = data_get($provider, 'items.data');
        $item = is_array($items) && count($items) === 1 && is_array($items[0]) ? $items[0] : null;
        $price = is_array($item) ? ($item['price'] ?? null) : null;
        $priceId = is_array($price) ? ($price['id'] ?? null) : $price;

        if (($provider['object'] ?? null) !== 'subscription'
            || ($provider['id'] ?? null) !== $subscriptionId
            || ($provider['livemode'] ?? null) !== $this->stripe->liveMode()
            || ! in_array($provider['status'] ?? null, ['canceled', 'incomplete_expired'], true)
            || $priceId !== $subscription->provider_price_id
            || (int) ($item['quantity'] ?? 0) !== 1
            || $providerCustomerId !== $customer->provider_customer_id
            || ($metadata['product'] ?? null) !== self::PRODUCT
            || ($metadata['core_workspace_id'] ?? null) !== (string) $workspace->getKey()
            || ($metadata['analytics_workspace_id'] ?? null) !== (string) $analyticsWorkspace->getKey()
            || ($metadata['provider_account_key'] ?? null) !== $accountKey
            || ! $attempt instanceof AnalyticsCheckoutAttempt
            || ($metadata['checkout_attempt_id'] ?? null) !== (string) $attempt->getKey()
            || $attempt->status !== 'completed'
            || $attempt->provider_checkout_session_id === null
            || (string) $attempt->provider_subscription_id !== $subscriptionId
            || (string) $attempt->provider_customer_id !== $customer->provider_customer_id) {
            throw new AnalyticsBillingException('Stripe has not confirmed a terminal Analytics subscription bound to this workspace.');
        }

        foreach ($this->stripe->listSubscriptionsForCustomer($customer->provider_customer_id) as $candidate) {
            $candidateMetadata = is_array($candidate['metadata'] ?? null) ? $candidate['metadata'] : [];
            if (($candidateMetadata['product'] ?? null) !== self::PRODUCT) {
                continue;
            }

            if (($candidateMetadata['core_workspace_id'] ?? null) !== (string) $workspace->getKey()
                || ($candidateMetadata['analytics_workspace_id'] ?? null) !== (string) $analyticsWorkspace->getKey()) {
                throw new AnalyticsBillingException('The Stripe customer has another Analytics workspace subscription binding.');
            }

            if ((string) ($candidate['id'] ?? '') !== $subscriptionId
                && ! in_array($candidate['status'] ?? null, ['canceled', 'incomplete_expired'], true)) {
                throw new AnalyticsBillingException('The Stripe customer already has another live Analytics subscription.');
            }
        }
    }

    private function providerReferenceId(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_array($value) && is_string($value['id'] ?? null) && $value['id'] !== '') {
            return $value['id'];
        }

        return null;
    }

    /** @return array{subscription:ProductSubscription,customer:BillingCustomer} */
    private function boundCurrentSubscription(CoreWorkspace $workspace, string $accountKey): array
    {
        if ($accountKey === '') {
            throw new AnalyticsBillingException('Analytics Stripe account configuration is incomplete.');
        }

        $current = CurrentProductSubscription::query()
            ->with('subscription.billingCustomer')
            ->where('workspace_id', $workspace->getKey())
            ->where('product', self::PRODUCT)
            ->first();
        $subscription = $current?->subscription;

        if (! $current instanceof CurrentProductSubscription
            || (string) $current->workspace_id !== (string) $workspace->getKey()
            || $current->product !== self::PRODUCT
            || ! $subscription instanceof ProductSubscription
            || (string) $subscription->workspace_id !== (string) $workspace->getKey()
            || $subscription->product !== self::PRODUCT
            || $subscription->provider !== 'stripe'
            || $subscription->provider_account_key !== $accountKey
            || ! is_string($subscription->provider_subscription_id)
            || $subscription->provider_subscription_id === '') {
            throw new AnalyticsBillingException('There is no bound Analytics Stripe subscription to manage.');
        }

        $customer = $this->validatedCustomerForSubscription($subscription, $workspace, $accountKey);
        if ($this->otherSubscriptionOwnsProviderId($subscription, $workspace, $accountKey)) {
            throw new AnalyticsBillingException('The Analytics Stripe subscription is bound to another product or workspace.');
        }

        return ['subscription' => $subscription, 'customer' => $customer];
    }

    private function validatedCustomerForSubscription(
        ProductSubscription $subscription,
        CoreWorkspace $workspace,
        string $accountKey,
    ): BillingCustomer {
        $customer = $subscription->billingCustomer;
        if (! $customer instanceof BillingCustomer
            || (string) $customer->workspace_id !== (string) $workspace->getKey()
            || $customer->provider !== 'stripe'
            || $customer->provider_account_key !== $accountKey
            || $customer->status !== 'active'
            || ! is_string($customer->provider_customer_id)
            || $customer->provider_customer_id === '') {
            throw new AnalyticsBillingException('There is no bound Analytics Stripe customer to manage.');
        }

        $customerConflict = BillingCustomer::query()
            ->where('provider', 'stripe')
            ->where('provider_account_key', $accountKey)
            ->where('provider_customer_id', $customer->provider_customer_id)
            ->where(function ($query) use ($workspace): void {
                $query->whereNull('workspace_id')->orWhere('workspace_id', '!=', $workspace->getKey());
            })
            ->exists();
        if ($customerConflict) {
            throw new AnalyticsBillingException('The Analytics Stripe customer is bound to another workspace.');
        }

        return $customer;
    }

    private function otherSubscriptionOwnsProviderId(
        ProductSubscription $subscription,
        CoreWorkspace $workspace,
        string $accountKey,
    ): bool {
        return ProductSubscription::query()
            ->where('provider', 'stripe')
            ->where('provider_account_key', $accountKey)
            ->where('provider_subscription_id', $subscription->provider_subscription_id)
            ->where(function ($query) use ($workspace): void {
                $query->where('workspace_id', '!=', $workspace->getKey())
                    ->orWhere('product', '!=', self::PRODUCT);
            })
            ->exists();
    }

    private function providerIdempotencyKey(string ...$parts): string
    {
        return 'analytics-'.implode('-', array_slice($parts, 0, 1)).'-'.hash('sha256', implode('|', $parts));
    }

    /** @param array<mixed> $left
     * @param  array<mixed>  $right
     */
    private function sameJsonValue(array $left, array $right): bool
    {
        return json_encode($this->canonicalizeJson($left), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            === json_encode($this->canonicalizeJson($right), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<mixed> $value
     * @return array<mixed>
     */
    private function canonicalizeJson(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => is_array($item) ? $this->canonicalizeJson($item) : $item, $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalizeJson($item);
            }
        }

        return $value;
    }
}
