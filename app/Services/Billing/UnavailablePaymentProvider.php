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
    public function available(): bool
    {
        return false;
    }

    public function createCustomer(string $accountId, string $name, string $email): string
    {
        throw new PaymentProviderUnavailable('Payments are not configured.');
    }

    public function checkoutUrl(string $customerId, string $accountId, array $items, string $successUrl, string $cancelUrl): string
    {
        throw new PaymentProviderUnavailable('Payments are not configured.');
    }

    public function syncSubscription(string $subscriptionId, array $items): SubscriptionState
    {
        throw new PaymentProviderUnavailable('Payments are not configured.');
    }

    public function subscription(string $subscriptionId): SubscriptionState
    {
        throw new PaymentProviderUnavailable('Payments are not configured.');
    }

    public function cancelSubscription(string $subscriptionId): void
    {
        throw new PaymentProviderUnavailable('Payments are not configured.');
    }

    public function portalUrl(string $customerId, string $returnUrl): string
    {
        throw new PaymentProviderUnavailable('Payments are not configured.');
    }

    public function invoices(string $customerId, int $limit = 12): array
    {
        return [];
    }

    public function reportUsage(string $customerId, string $eventName, int $quantity, CarbonInterface $at, string $idempotencyKey): void {}

    public function verifyWebhook(string $payload, string $signature): WebhookEvent
    {
        throw new InvalidWebhook('Payments are not configured.');
    }
}
