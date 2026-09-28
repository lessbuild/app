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
     */
    public function available(): bool
    {
        return false;
    }

    /**
     * Refuses, since there's no provider to create customers at.
     */
    public function createCustomer(string $accountId, string $name, string $email): string
    {
        throw new PaymentProviderUnavailable('Payments are not configured.');
    }

    /**
     * Refuses, since there's nothing to check out with.
     */
    public function checkoutUrl(string $customerId, string $accountId, array $items, string $successUrl, string $cancelUrl): string
    {
        throw new PaymentProviderUnavailable('Payments are not configured.');
    }

    /**
     * Refuses; there are no subscriptions without a provider.
     */
    public function syncSubscription(string $subscriptionId, array $items): SubscriptionState
    {
        throw new PaymentProviderUnavailable('Payments are not configured.');
    }

    /**
     * Refuses; there are no subscriptions without a provider.
     */
    public function subscription(string $subscriptionId): SubscriptionState
    {
        throw new PaymentProviderUnavailable('Payments are not configured.');
    }

    /**
     * Refuses; there are no subscriptions without a provider.
     */
    public function cancelSubscription(string $subscriptionId): void
    {
        throw new PaymentProviderUnavailable('Payments are not configured.');
    }

    /**
     * Refuses, since there's no portal.
     */
    public function portalUrl(string $customerId, string $returnUrl): string
    {
        throw new PaymentProviderUnavailable('Payments are not configured.');
    }

    /**
     * None, since nothing was ever billed.
     */
    public function invoices(string $customerId, int $limit = 12): array
    {
        return [];
    }

    /**
     * Does nothing: usage beyond an allowance can't be billed without a provider.
     */
    public function reportUsage(string $customerId, string $eventName, int $quantity, CarbonInterface $at, string $idempotencyKey): void {}

    /**
     * Refuses every webhook, since there's no secret to check it with.
     */
    public function verifyWebhook(string $payload, string $signature): WebhookEvent
    {
        throw new InvalidWebhook('Payments are not configured.');
    }
}
