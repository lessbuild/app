<?php

namespace App\Modules\Deployer\Services;

use App\Modules\Deployer\Exceptions\StripeBillingException;
use App\Modules\Deployer\Models\Organization;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class DeployerStripeBillingClient
{
    public function configured(): bool
    {
        return filled(config('cashier.secret'));
    }

    public function checkoutConfigured(string $plan, string $interval): bool
    {
        return $this->configured() && $this->priceId($plan, $interval) !== null;
    }

    /** @return array{id:string,url:string} */
    public function createCheckoutSession(
        Organization $organization,
        string $coreWorkspaceId,
        string $email,
        string $plan,
        string $interval,
        ?string $customerId,
        bool $eligibleForTrial,
        string $idempotencyKey,
    ): array {
        $priceId = $this->priceId($plan, $interval);
        if (! $this->configured() || $priceId === null) {
            throw new StripeBillingException('Stripe checkout is not configured for this plan.');
        }

        $metadata = [
            'core_workspace_id' => $coreWorkspaceId,
            'organization_id' => (string) $organization->getKey(),
            'plan' => $plan,
            'interval' => $interval,
        ];
        $parameters = [
            'mode' => 'subscription',
            'expires_at' => now('UTC')->addMinutes(31)->timestamp,
            'line_items[0][price]' => $priceId,
            'line_items[0][quantity]' => '1',
            'allow_promotion_codes' => 'true',
            'client_reference_id' => $coreWorkspaceId,
            'success_url' => route('billing.index', ['organization_id' => $organization->getKey(), 'checkout' => 'success']),
            'cancel_url' => route('billing.index', ['organization_id' => $organization->getKey(), 'checkout' => 'cancelled']),
        ];

        foreach ($metadata as $key => $value) {
            $parameters["metadata[{$key}]"] = $value;
            $parameters["subscription_data[metadata][{$key}]"] = $value;
        }

        if ($customerId !== null) {
            $parameters['customer'] = $customerId;
        } else {
            $parameters['customer_email'] = $email;
        }

        $includedSeats = config("billing.plans.{$plan}.included_seats");
        $extraSeats = is_null($includedSeats) ? 0 : max(0, $organization->members()->count() - (int) $includedSeats);
        $seatPrice = config("billing.plans.{$plan}.{$interval}_seat_price_id");
        if ($extraSeats > 0 && is_string($seatPrice) && $seatPrice !== '') {
            $parameters['line_items[1][price]'] = $seatPrice;
            $parameters['line_items[1][quantity]'] = (string) $extraSeats;
        }

        $trialDays = $eligibleForTrial ? max(0, (int) config('billing.trial_days', 0)) : 0;
        if ($trialDays > 0) {
            $parameters['subscription_data[trial_period_days]'] = (string) $trialDays;
        }

        $response = $this->request()
            ->asForm()
            ->withHeaders(['Idempotency-Key' => 'deployer-checkout-'.$idempotencyKey])
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

    public function createPortalSession(string $customerId): string
    {
        if (! $this->configured() || ! (bool) config('billing.portal_enabled', true)) {
            throw new StripeBillingException('Stripe customer portal is not available.');
        }

        $response = $this->request()->asForm()->post($this->endpoint('v1/billing_portal/sessions'), [
            'customer' => $customerId,
            'return_url' => route('billing.index'),
        ]);

        return $this->responseUrl($response, 'open the billing portal');
    }

    public function setCancelAtPeriodEnd(string $subscriptionId, bool $cancel): void
    {
        if (! $this->configured() || ! preg_match('/^sub_[A-Za-z0-9]+$/', $subscriptionId)) {
            throw new StripeBillingException('Stripe subscription management is unavailable.');
        }

        $response = $this->request()->asForm()->post($this->endpoint('v1/subscriptions/'.rawurlencode($subscriptionId)), [
            'cancel_at_period_end' => $cancel ? 'true' : 'false',
        ]);

        if (! $response->successful()) {
            throw new StripeBillingException('Stripe could not update this subscription.');
        }
    }

    /** @return array<string,mixed> */
    public function retrieveEvent(string $eventId): array
    {
        if (! $this->configured() || ! preg_match('/^evt_[A-Za-z0-9]+$/', $eventId)) {
            throw new StripeBillingException('The Stripe event cannot be retrieved.');
        }

        $response = $this->request()->get($this->endpoint('v1/events/'.rawurlencode($eventId)));
        $event = $response->json();
        if (! $response->successful()
            || ! is_array($event)
            || ($event['id'] ?? null) !== $eventId
            || ! is_string($event['type'] ?? null)
            || ! is_array($event['data']['object'] ?? null)) {
            throw new StripeBillingException('Stripe could not retrieve the billing event.');
        }

        return $event;
    }

    public function priceId(string $plan, string $interval): ?string
    {
        $prices = config('billing.plans.'.$plan, []);
        if (! is_array($prices)) {
            return null;
        }

        $priceId = $prices[$interval.'_price_id'] ?? ($interval === 'monthly' ? ($prices['price_id'] ?? null) : null);

        return is_string($priceId) && $priceId !== '' ? $priceId : null;
    }

    public function planForPrice(?string $priceId): ?string
    {
        if ($priceId === null) {
            return null;
        }

        foreach (array_reverse(config('billing.plans', []), true) as $plan => $details) {
            if (! is_array($details) || $plan === 'free') {
                continue;
            }

            $prices = array_filter(array_unique([
                $details['price_id'] ?? null,
                $details['monthly_price_id'] ?? null,
                $details['yearly_price_id'] ?? null,
            ]));
            if (in_array($priceId, $prices, true)) {
                return (string) $plan;
            }
        }

        return null;
    }

    private function request(): PendingRequest
    {
        return Http::withBasicAuth((string) config('cashier.secret'), '')
            ->acceptJson()
            ->timeout(10);
    }

    private function endpoint(string $path): string
    {
        return rtrim((string) config('cashier.api_url', 'https://api.stripe.com'), '/').'/'.$path;
    }

    private function responseUrl(Response $response, string $operation): string
    {
        $url = $response->json('url');
        if (! $response->successful() || ! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new StripeBillingException('Stripe could not '.$operation.'.');
        }

        return $url;
    }
}
