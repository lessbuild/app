<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Exceptions\StripeBillingException;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class StripeBillingClient
{
    public function configured(): bool
    {
        return filled(config('monitor.beacon.billing.stripe.secret'));
    }

    public function checkoutConfigured(string $plan): bool
    {
        return $this->configured() && $this->priceId($plan) !== null;
    }

    public function portalConfigured(Workspace $workspace): bool
    {
        return $this->configured()
            && (bool) config('monitor.beacon.billing.stripe.portal_enabled', true)
            && filled($workspace->stripe_customer_id);
    }

    /** @return array{id: string, url: string} */
    public function createCheckoutSession(Workspace $workspace, User $owner, string $plan): array
    {
        $priceId = $this->priceId($plan);

        if (! $this->configured() || $priceId === null) {
            throw new StripeBillingException('Stripe checkout is not configured for this plan.');
        }

        $parameters = [
            'mode' => 'subscription',
            'line_items[0][price]' => $priceId,
            'line_items[0][quantity]' => '1',
            'success_url' => route('monitor.settings.billing.success').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('monitor.settings.billing.cancel'),
            'client_reference_id' => (string) $workspace->getKey(),
            'metadata[workspace_id]' => (string) $workspace->getKey(),
            'metadata[plan]' => $plan,
            'subscription_data[metadata][workspace_id]' => (string) $workspace->getKey(),
            'subscription_data[metadata][plan]' => $plan,
        ];

        if (filled($workspace->stripe_customer_id)) {
            $parameters['customer'] = $workspace->stripe_customer_id;
        } else {
            $parameters['customer_email'] = $owner->email;
        }

        $response = $this->request()
            ->asForm()
            ->withHeaders(['Idempotency-Key' => 'beacon-checkout-'.Str::uuid()])
            ->post($this->endpoint('v1/checkout/sessions'), $parameters);

        if (! $response->successful()) {
            throw new StripeBillingException('Stripe could not start checkout.');
        }

        $id = $response->json('id');
        $url = $response->json('url');

        if (! is_string($id) || $id === '' || ! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new StripeBillingException('Stripe returned an invalid checkout session.');
        }

        return ['id' => $id, 'url' => $url];
    }

    public function createPortalSession(Workspace $workspace): string
    {
        if (! $this->portalConfigured($workspace)) {
            throw new StripeBillingException('Stripe customer portal is not available for this workspace.');
        }

        $response = $this->request()
            ->asForm()
            ->post($this->endpoint('v1/billing_portal/sessions'), [
                'customer' => $workspace->stripe_customer_id,
                'return_url' => route('monitor.settings.billing'),
            ]);

        return $this->responseUrl($response, 'open the billing portal');
    }

    public function priceId(string $plan): ?string
    {
        $priceId = config('monitor.beacon.plans.'.$plan.'.stripe_price_id');

        return is_string($priceId) && $priceId !== '' ? $priceId : null;
    }

    public function planForPrice(?string $priceId): ?string
    {
        if ($priceId === null) {
            return null;
        }

        $plans = config('monitor.beacon.plans', []);
        if (! is_array($plans)) {
            return null;
        }

        foreach ($plans as $plan => $configuration) {
            if (! is_array($configuration) || (int) ($configuration['price'] ?? 0) <= 0) {
                continue;
            }

            if (($configuration['stripe_price_id'] ?? null) === $priceId) {
                return (string) $plan;
            }
        }

        return null;
    }

    private function request(): PendingRequest
    {
        return Http::withBasicAuth((string) config('monitor.beacon.billing.stripe.secret'), '')
            ->acceptJson()
            ->timeout(10);
    }

    private function endpoint(string $path): string
    {
        return rtrim((string) config('monitor.beacon.billing.stripe.api_url', 'https://api.stripe.com'), '/').'/'.$path;
    }

    private function responseUrl(Response $response, string $operation): string
    {
        if (! $response->successful()) {
            throw new StripeBillingException('Stripe could not '.$operation.'.');
        }

        $url = $response->json('url');

        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new StripeBillingException('Stripe returned an invalid billing URL.');
        }

        return $url;
    }
}
