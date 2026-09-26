<?php

namespace App\Modules\Analytics\Services\Billing;

use App\Core\Models\AnalyticsCheckoutAttempt;
use App\Core\Models\Workspace as CoreWorkspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Provider inspection and operator recovery for aged Checkout calls with no saved session. */
final class ReconcileAnalyticsCheckoutAttempt
{
    private const PRODUCT = 'analytics';

    public function __construct(private readonly AnalyticsStripeClient $stripe) {}

    /** @return array{result:string,session_id:?string,status:?string,changed:bool} */
    public function handle(string $attemptId, bool $apply = false): array
    {
        $attempt = AnalyticsCheckoutAttempt::query()->find($attemptId);
        if (! $attempt instanceof AnalyticsCheckoutAttempt) {
            throw new AnalyticsBillingException('The Analytics checkout attempt was not found.');
        }
        if ($attempt->provider_checkout_session_id !== null) {
            throw new AnalyticsBillingException('The Analytics checkout attempt already has a provider session.');
        }
        if (! in_array($attempt->status, ['pending', 'failed'], true)
            || $attempt->provider_subscription_id !== null
            || $attempt->created_at === null
            || $attempt->created_at->gt(now('UTC')->subHours(12))) {
            throw new AnalyticsBillingException('Only an aged unresolved Analytics checkout attempt can be reconciled.');
        }
        if ($attempt->provider_account_key !== $this->stripe->retrieveAccountId()) {
            throw new AnalyticsBillingException('The Analytics checkout attempt belongs to another Stripe account.');
        }

        $matches = $this->matchingProviderSessions($attempt);
        if (count($matches) > 1) {
            throw new AnalyticsBillingException('Stripe returned more than one Checkout Session for this Analytics attempt.');
        }

        if ($matches === []) {
            // Negative provider inventory does not establish finality for a
            // timed-out create. Keep this attempt exclusive until a matching
            // provider object can be bound through signed reconciliation.
            return ['result' => 'provider_session_absent', 'session_id' => null, 'status' => null, 'changed' => false];
        }

        $session = $this->stripe->retrieveCheckoutSession((string) $matches[0]['id']);
        $this->assertSessionBinding($attempt, $session);
        $sessionStatus = $session['status'] ?? null;
        $sessionCustomerId = $this->providerReferenceId($session['customer'] ?? null);
        $subscriptionField = $session['subscription'] ?? null;
        $subscriptionId = $this->providerReferenceId($subscriptionField);
        $expiresAt = is_int($session['expires_at'] ?? null) ? $session['expires_at'] : null;

        if (! in_array($sessionStatus, ['open', 'complete', 'expired'], true)
            || ! array_key_exists('subscription', $session)
            || ($subscriptionField !== null && $subscriptionId === null)
            || ($sessionStatus === 'open' && $expiresAt === null)
            || ($sessionStatus === 'complete' && $subscriptionId === null)) {
            throw new AnalyticsBillingException('Stripe returned an incomplete Analytics Checkout Session for reconciliation.');
        }

        if ($sessionCustomerId === null && $attempt->provider_customer_id !== null) {
            throw new AnalyticsBillingException('Stripe returned an Analytics Checkout Session without its bound customer.');
        }
        if ($subscriptionId !== null) {
            $subscription = $this->stripe->retrieveSubscription($subscriptionId);
            $this->assertSubscriptionBinding($attempt, $sessionCustomerId, $subscription);
        }

        if (! $apply) {
            return [
                'result' => 'provider_session_found',
                'session_id' => (string) $session['id'],
                'status' => $sessionStatus,
                'changed' => false,
            ];
        }

        $resolvedStatus = $sessionStatus === 'open'
            ? 'open'
            : ($subscriptionId !== null ? 'completed' : 'expired');
        $this->attachProviderSession($attempt, $session, $resolvedStatus, $sessionCustomerId, $subscriptionId);

        return [
            'result' => 'provider_session_found',
            'session_id' => (string) $session['id'],
            'status' => $sessionStatus,
            'changed' => true,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function matchingProviderSessions(AnalyticsCheckoutAttempt $attempt): array
    {
        $createdFrom = max(0, $attempt->created_at->copy()->subMinutes(5)->timestamp);
        $sessions = $this->stripe->listCheckoutSessions(
            $createdFrom,
            now('UTC')->timestamp,
            is_string($attempt->provider_customer_id) ? $attempt->provider_customer_id : null,
        );
        $attemptId = (string) $attempt->getKey();
        $matches = [];
        foreach ($sessions as $candidate) {
            $metadata = is_array($candidate['metadata'] ?? null) ? $candidate['metadata'] : [];
            if (($candidate['client_reference_id'] ?? null) === $attemptId
                || ($metadata['checkout_attempt_id'] ?? null) === $attemptId) {
                $matches[] = $candidate;
            }
        }

        return $matches;
    }

    /** @param array<string, mixed> $session */
    private function assertSessionBinding(AnalyticsCheckoutAttempt $attempt, array $session): void
    {
        $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
        foreach ([
            'product' => self::PRODUCT,
            'core_workspace_id' => (string) $attempt->core_workspace_id,
            'analytics_workspace_id' => (string) $attempt->analytics_workspace_id,
            'provider_account_key' => (string) $attempt->provider_account_key,
            'checkout_attempt_id' => (string) $attempt->getKey(),
        ] as $key => $value) {
            if (($metadata[$key] ?? null) !== $value) {
                throw new AnalyticsBillingException('The provider Checkout Session is not bound to this Analytics attempt.');
            }
        }

        $customerId = $this->providerReferenceId($session['customer'] ?? null);
        if (($session['id'] ?? null) === null
            || ($session['mode'] ?? null) !== 'subscription'
            || ($session['client_reference_id'] ?? null) !== (string) $attempt->getKey()
            || ($attempt->provider_customer_id !== null && $customerId !== $attempt->provider_customer_id)) {
            throw new AnalyticsBillingException('The provider Checkout Session is not bound to this Analytics attempt.');
        }
    }

    /** @param array<string, mixed> $subscription */
    private function assertSubscriptionBinding(
        AnalyticsCheckoutAttempt $attempt,
        ?string $customerId,
        array $subscription,
    ): void {
        $metadata = is_array($subscription['metadata'] ?? null) ? $subscription['metadata'] : [];
        $items = data_get($subscription, 'items.data');
        $item = is_array($items) && count($items) === 1 && is_array($items[0]) ? $items[0] : null;
        $price = is_array($item) ? ($item['price'] ?? null) : null;
        $priceId = is_array($price) ? ($price['id'] ?? null) : $price;

        if (($subscription['object'] ?? null) !== 'subscription'
            || ($subscription['livemode'] ?? null) !== $this->stripe->liveMode()
            || ! in_array($subscription['status'] ?? null, [
                'active', 'trialing', 'past_due', 'unpaid', 'canceled', 'incomplete', 'incomplete_expired', 'paused',
            ], true)
            || $customerId === null
            || $this->providerReferenceId($subscription['customer'] ?? null) !== $customerId
            || ($metadata['product'] ?? null) !== self::PRODUCT
            || ($metadata['core_workspace_id'] ?? null) !== (string) $attempt->core_workspace_id
            || ($metadata['analytics_workspace_id'] ?? null) !== (string) $attempt->analytics_workspace_id
            || ($metadata['provider_account_key'] ?? null) !== (string) $attempt->provider_account_key
            || ($metadata['checkout_attempt_id'] ?? null) !== (string) $attempt->getKey()
            || ($priceId ?? null) !== $attempt->provider_price_id
            || (int) ($item['quantity'] ?? 0) !== 1) {
            throw new AnalyticsBillingException('The provider subscription is not bound to this Analytics checkout attempt.');
        }
    }

    /** @param array<string, mixed> $session */
    private function attachProviderSession(
        AnalyticsCheckoutAttempt $attempt,
        array $session,
        string $status,
        ?string $customerId,
        ?string $subscriptionId,
    ): void {
        $this->withAttemptWriteFence($attempt, function (AnalyticsCheckoutAttempt $fresh) use (
            $session,
            $status,
            $customerId,
            $subscriptionId,
        ): void {
            $fresh->forceFill([
                'provider_checkout_session_id' => (string) $session['id'],
                'provider_customer_id' => $customerId ?? $fresh->provider_customer_id,
                'provider_subscription_id' => $subscriptionId,
                'status' => $status,
                'expires_at' => is_int($session['expires_at'] ?? null)
                    ? Carbon::createFromTimestamp($session['expires_at'], 'UTC')
                    : $fresh->expires_at,
                'completed_at' => $status === 'completed' ? ($fresh->completed_at ?? now('UTC')) : $fresh->completed_at,
                'failure_code' => null,
            ])->save();
        });
    }

    /** @param callable(AnalyticsCheckoutAttempt):void $update */
    private function withAttemptWriteFence(AnalyticsCheckoutAttempt $attempt, callable $update): void
    {
        DB::connection('core')->transaction(function () use ($attempt, $update): void {
            $fenced = CoreWorkspace::query()
                ->whereKey($attempt->core_workspace_id)
                ->update(['updated_at' => now('UTC')]);
            if ($fenced !== 1) {
                throw new AnalyticsBillingException('The Analytics workspace disappeared during checkout reconciliation.');
            }

            $workspace = CoreWorkspace::query()->whereKey($attempt->core_workspace_id)->lockForUpdate()->first();
            $fresh = AnalyticsCheckoutAttempt::query()->whereKey($attempt->getKey())->lockForUpdate()->first();
            if ($workspace === null
                || ! $fresh instanceof AnalyticsCheckoutAttempt
                || (string) $fresh->core_workspace_id !== (string) $attempt->core_workspace_id
                || (string) $fresh->analytics_workspace_id !== (string) $attempt->analytics_workspace_id
                || $fresh->provider_account_key !== $attempt->provider_account_key
                || $fresh->provider_checkout_session_id !== null
                || $fresh->provider_subscription_id !== null
                || ! in_array($fresh->status, ['pending', 'failed'], true)) {
                throw new AnalyticsBillingException('The Analytics checkout attempt changed during provider reconciliation.');
            }

            $update($fresh);
        }, attempts: 3);
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
}
