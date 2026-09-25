<?php

namespace App\Modules\Analytics\Services\Billing;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Analytics-only Stripe transport. Every operation first verifies the configured account. */
final class AnalyticsStripeClient
{
    private ?string $verifiedAccountId = null;

    private ?string $verifiedConfigurationHash = null;

    public function configured(): bool
    {
        return is_string(config('analytics.billing.stripe.secret'))
            && preg_match('/^sk_(test|live)_[A-Za-z0-9]+$/', (string) config('analytics.billing.stripe.secret')) === 1
            && $this->configuredAccountId() !== null
            && $this->validApiUrl(config('analytics.billing.stripe.api_url', 'https://api.stripe.com'));
    }

    public function liveMode(): bool
    {
        return is_string(config('analytics.billing.stripe.secret'))
            && str_starts_with((string) config('analytics.billing.stripe.secret'), 'sk_live_');
    }

    public function configuredAccountId(): ?string
    {
        $accountId = config('analytics.billing.stripe.account_id');

        return is_string($accountId) && preg_match('/^acct_[A-Za-z0-9]+$/', $accountId)
            ? $accountId
            : null;
    }

    public function retrieveAccountId(): string
    {
        if (! $this->configured()) {
            throw new AnalyticsBillingException('Analytics Stripe account configuration is incomplete.');
        }

        $configurationHash = hash('sha256', implode("\0", [
            (string) config('analytics.billing.stripe.secret'),
            (string) $this->configuredAccountId(),
            (string) config('analytics.billing.stripe.api_url', 'https://api.stripe.com'),
        ]));
        if ($this->verifiedConfigurationHash === $configurationHash
            && $this->verifiedAccountId === $this->configuredAccountId()) {
            return $this->verifiedAccountId;
        }

        $response = $this->baseRequest()->get($this->endpoint('v1/account'));
        $this->assertSuccessful($response, 'verify the Analytics Stripe account');

        $accountId = $response->json('id');
        if (! is_string($accountId) || $accountId !== $this->configuredAccountId()) {
            throw new AnalyticsBillingException('Stripe returned an account that does not match Analytics billing configuration.');
        }

        $this->verifiedConfigurationHash = $configurationHash;
        $this->verifiedAccountId = $accountId;

        return $accountId;
    }

    /** @param array<string, mixed> $parameters
     * @return array{id:string,url:string,status:?string,expires_at:?int,customer_id:?string}
     */
    public function createCheckoutSession(array $parameters, string $idempotencyKey): array
    {
        $this->assertIdempotencyKey($idempotencyKey);
        $response = $this->authorizedRequest()
            ->asForm()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post($this->endpoint('v1/checkout/sessions'), $parameters);
        $this->assertSuccessful($response, 'start Analytics checkout');

        $session = $response->json();
        if (! is_array($session)
            || ! is_string($session['id'] ?? null)
            || ! preg_match('/^cs_(?:test_|live_)?[A-Za-z0-9]+$/', $session['id'])
            || ! is_string($session['url'] ?? null)
            || ! $this->isProviderUrl($session['url'], ['checkout.stripe.com'])) {
            throw new AnalyticsBillingException('Stripe returned an invalid Analytics checkout session.');
        }

        return [
            'id' => $session['id'],
            'url' => $session['url'],
            'status' => is_string($session['status'] ?? null) ? $session['status'] : null,
            'expires_at' => is_int($session['expires_at'] ?? null) ? $session['expires_at'] : null,
            'customer_id' => $this->referenceId($session['customer'] ?? null),
        ];
    }

    /** @return array{id:string,url:string} */
    public function createPortalSession(string $customerId, string $returnUrl, string $idempotencyKey): array
    {
        $this->assertId($customerId, 'cus');
        $this->assertIdempotencyKey($idempotencyKey);
        $response = $this->authorizedRequest()
            ->asForm()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post($this->endpoint('v1/billing_portal/sessions'), [
                'customer' => $customerId,
                'return_url' => $returnUrl,
            ]);
        $this->assertSuccessful($response, 'open Analytics billing portal');

        $session = $response->json();
        if (! is_array($session)
            || ! is_string($session['id'] ?? null)
            || ! preg_match('/^bps_[A-Za-z0-9]+$/', $session['id'])
            || ! is_string($session['url'] ?? null)
            || ! $this->isProviderUrl($session['url'], ['billing.stripe.com'])) {
            throw new AnalyticsBillingException('Stripe returned an invalid Analytics portal session.');
        }

        return ['id' => $session['id'], 'url' => $session['url']];
    }

    /** @return array<string, mixed> */
    public function cancelSubscription(string $subscriptionId, bool $atPeriodEnd, string $idempotencyKey): array
    {
        $this->assertId($subscriptionId, 'sub');
        $this->assertIdempotencyKey($idempotencyKey);

        $request = $this->authorizedRequest()
            ->asForm()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey]);
        $response = $atPeriodEnd
            ? $request->post($this->endpoint('v1/subscriptions/'.rawurlencode($subscriptionId)), [
                'cancel_at_period_end' => 'true',
            ])
            : $request->delete($this->endpoint('v1/subscriptions/'.rawurlencode($subscriptionId)));
        $this->assertSuccessful($response, 'cancel the Analytics subscription');

        $subscription = $response->json();
        if (! is_array($subscription) || ($subscription['id'] ?? null) !== $subscriptionId) {
            throw new AnalyticsBillingException('Stripe returned an invalid Analytics subscription.');
        }

        return $subscription;
    }

    /** @return array<string, mixed> */
    public function retrieveSubscription(string $subscriptionId): array
    {
        $this->assertId($subscriptionId, 'sub');
        $response = $this->authorizedRequest()->get($this->endpoint('v1/subscriptions/'.rawurlencode($subscriptionId)));
        $this->assertSuccessful($response, 'retrieve the Analytics subscription');

        $subscription = $response->json();
        if (! is_array($subscription) || ($subscription['id'] ?? null) !== $subscriptionId) {
            throw new AnalyticsBillingException('Stripe returned an invalid Analytics subscription.');
        }

        return $subscription;
    }

    /** @return array<string, mixed> */
    public function retrieveCheckoutSession(string $sessionId): array
    {
        $this->assertId($sessionId, 'cs');
        $response = $this->authorizedRequest()->get($this->endpoint('v1/checkout/sessions/'.rawurlencode($sessionId)));
        $this->assertSuccessful($response, 'retrieve the Analytics checkout session');

        $session = $response->json();
        if (! is_array($session) || ($session['id'] ?? null) !== $sessionId) {
            throw new AnalyticsBillingException('Stripe returned an invalid Analytics checkout session.');
        }

        return $session;
    }

    /** @return array<string, mixed> */
    public function retrieveEvent(string $eventId): array
    {
        $this->assertId($eventId, 'evt');
        $response = $this->authorizedRequest()->get($this->endpoint('v1/events/'.rawurlencode($eventId)));
        $this->assertSuccessful($response, 'retrieve the Analytics Stripe event');

        $event = $response->json();
        if (! is_array($event)
            || ($event['id'] ?? null) !== $eventId
            || ! is_string($event['type'] ?? null)
            || ! is_array($event['data']['object'] ?? null)) {
            throw new AnalyticsBillingException('Stripe returned an invalid Analytics event.');
        }

        return $event;
    }

    /** @return array<string, mixed> */
    public function retrievePrice(string $priceId): array
    {
        $this->assertId($priceId, 'price');
        $response = $this->authorizedRequest()->get($this->endpoint('v1/prices/'.rawurlencode($priceId)));
        $this->assertSuccessful($response, 'verify the Analytics Stripe price');

        $price = $response->json();
        if (! is_array($price)
            || ($price['id'] ?? null) !== $priceId
            || ! is_bool($price['active'] ?? null)
            || ! is_string($price['type'] ?? null)) {
            throw new AnalyticsBillingException('Stripe returned an invalid Analytics price.');
        }

        return $price;
    }

    private function authorizedRequest(): PendingRequest
    {
        $this->retrieveAccountId();

        return $this->baseRequest();
    }

    private function baseRequest(): PendingRequest
    {
        if (! $this->configured()) {
            throw new AnalyticsBillingException('Analytics Stripe account configuration is incomplete.');
        }

        return Http::withBasicAuth((string) config('analytics.billing.stripe.secret'), '')
            ->acceptJson()
            ->timeout(10);
    }

    private function endpoint(string $path): string
    {
        return rtrim((string) config('analytics.billing.stripe.api_url', 'https://api.stripe.com'), '/').'/'.$path;
    }

    private function assertSuccessful(Response $response, string $operation): void
    {
        if (! $response->successful()) {
            throw new AnalyticsBillingException('Stripe could not '.$operation.'.');
        }
    }

    private function assertId(string $value, string $prefix): void
    {
        $valid = $prefix === 'cs'
            ? preg_match('/^cs_(?:test_|live_)?[A-Za-z0-9]+$/', $value)
            : preg_match('/^'.preg_quote($prefix, '/').'_[A-Za-z0-9]+$/', $value);

        if ($valid !== 1) {
            throw new AnalyticsBillingException('The Analytics Stripe identifier is invalid.');
        }
    }

    private function assertIdempotencyKey(string $idempotencyKey): void
    {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 255 || ! preg_match('/^[A-Za-z0-9._:-]+$/', $idempotencyKey)) {
            throw new AnalyticsBillingException('The Analytics billing request key is invalid.');
        }
    }

    private function referenceId(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_array($value) && is_string($value['id'] ?? null) && $value['id'] !== '') {
            return $value['id'];
        }

        return null;
    }

    private function validApiUrl(mixed $url): bool
    {
        if (! is_string($url) || $url === '') {
            return false;
        }

        $parts = parse_url($url);

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            return false;
        }

        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        if ($host === 'api.stripe.com' && ($port === null || (int) $port === 443)) {
            return true;
        }

        // Test endpoints may be replaced with an HTTPS fake only when a Stripe
        // test secret is configured. Production always uses Stripe's API origin.
        return app()->environment('testing')
            && str_starts_with((string) config('analytics.billing.stripe.secret'), 'sk_test_');
    }

    /** @param list<string> $allowedHosts */
    private function isProviderUrl(string $url, array $allowedHosts): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && is_string($parts['host'] ?? null)
            && in_array(strtolower($parts['host']), $allowedHosts, true)
            && (! isset($parts['port']) || (int) $parts['port'] === 443)
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }
}
