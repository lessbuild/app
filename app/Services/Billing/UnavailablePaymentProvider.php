<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Contracts\PaymentProvider;
use App\Data\Billing\SubscriptionState;
use App\Data\Billing\WebhookEvent;
use App\Exceptions\InvalidWebhook;
use App\Exceptions\PaymentProviderUnavailable;
use Carbon\CarbonInterface;

/** Used when STRIPE_SECRET isn't set: free tiers work, paid ones can't be bought. */
final class UnavailablePaymentProvider implements PaymentProvider
{
    /**
     * Never: no payment provider is configured.
     *
     * @return bool
     */
    public function available(): bool
    {
        return false;
    }

    /**
     * Refuses, since there's no provider to create customers at.
     *
     * @param  string  $accountId
     * @param  string  $name
     * @param  string  $email
     * @return string
     */
    public function createCustomer(string $accountId, string $name, string $email): string
    {
        throw new PaymentProviderUnavailable('Payments are not configured.');
    }

    /**
     * Refuses, since there's nothing to check out with.
     *
     * @param  string  $customerId
     * @param  string  $accountId
     * @param  list<\App\Data\Billing\LineItem>  $items
     * @param  string  $successUrl
     * @param  string  $cancelUrl
     * @return string
     */
    public function checkoutUrl(string $customerId, string $accountId, array $items, string $successUrl, string $cancelUrl): string
    {
        throw new PaymentProviderUnavailable('Payments are not configured.');
    }

    /**
     * Refuses; there are no subscriptions without a provider.
     *
     * @param  string  $subscriptionId
     * @param  list<\App\Data\Billing\LineItem>  $items
     * @return SubscriptionState
     */
    public function syncSubscription(string $subscriptionId, array $items): SubscriptionState
    {
        throw new PaymentProviderUnavailable('Payments are not configured.');
    }

    /**
     * Refuses; there are no subscriptions without a provider.
     *
     * @param  string  $subscriptionId
     * @return SubscriptionState
     */
    public function subscription(string $subscriptionId): SubscriptionState
    {
        throw new PaymentProviderUnavailable('Payments are not configured.');
    }

    /**
     * Refuses; there are no subscriptions without a provider.
     *
     * @param  string  $subscriptionId
     * @return void
     */
    public function cancelSubscription(string $subscriptionId): void
    {
        throw new PaymentProviderUnavailable('Payments are not configured.');
    }

    /**
     * Refuses, since there's no portal.
     *
     * @param  string  $customerId
     * @param  string  $returnUrl
     * @return string
     */
    public function portalUrl(string $customerId, string $returnUrl): string
    {
        throw new PaymentProviderUnavailable('Payments are not configured.');
    }

    /**
     * None, since nothing was ever billed.
     *
     * @param  string  $customerId
     * @param  int  $limit
     * @return list<\App\Data\Billing\InvoiceSummary>
     */
    public function invoices(string $customerId, int $limit = 12): array
    {
        return [];
    }

    /**
     * Does nothing: usage beyond an allowance can't be billed without a provider.
     *
     * @param  string  $customerId
     * @param  string  $eventName
     * @param  int  $quantity
     * @param  CarbonInterface  $at
     * @param  string  $idempotencyKey
     * @return void
     */
    public function reportUsage(string $customerId, string $eventName, int $quantity, CarbonInterface $at, string $idempotencyKey): void {}

    /**
     * Refuses every webhook, since there's no secret to check it with.
     *
     * @param  string  $payload
     * @param  string  $signature
     * @return WebhookEvent
     */
    public function verifyWebhook(string $payload, string $signature): WebhookEvent
    {
        throw new InvalidWebhook('Payments are not configured.');
    }
}
