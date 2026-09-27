<?php

declare(strict_types=1);

namespace App\Domain\Billing\Contracts;

use App\Domain\Billing\Data\InvoiceSummary;
use App\Domain\Billing\Data\LineItem;
use App\Domain\Billing\Data\SubscriptionState;
use App\Domain\Billing\Data\WebhookEvent;
use App\Domain\Billing\Exceptions\InvalidWebhook;
use App\Domain\Billing\Exceptions\PaymentProviderUnavailable;
use Carbon\CarbonInterface;

/** Stripe, behind an interface (implementation in app/Services/Billing; tests use a fake). */
interface PaymentProvider
{
    /** False when this environment has no payment provider configured; paid tiers can't be bought then. */
    public function available(): bool;

    /** @throws PaymentProviderUnavailable */
    public function createCustomer(string $accountId, string $name, string $email): string;

    /**
     * A hosted checkout that creates the subscription with these items.
     *
     * @param  list<LineItem>  $items
     */
    public function checkoutUrl(string $customerId, string $accountId, array $items, string $successUrl, string $cancelUrl): string;

    /**
     * Make the subscription's items exactly these (adding, changing and removing items, prorated).
     *
     * @param  list<LineItem>  $items
     */
    public function syncSubscription(string $subscriptionId, array $items): SubscriptionState;

    public function subscription(string $subscriptionId): SubscriptionState;

    public function cancelSubscription(string $subscriptionId): void;

    public function portalUrl(string $customerId, string $returnUrl): string;

    /** @return list<InvoiceSummary> newest first */
    public function invoices(string $customerId, int $limit = 12): array;

    public function reportUsage(string $customerId, string $eventName, int $quantity, CarbonInterface $at, string $idempotencyKey): void;

    /** @throws InvalidWebhook */
    public function verifyWebhook(string $payload, string $signature): WebhookEvent;
}
