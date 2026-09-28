<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\Billing\InvoiceSummary;
use App\Data\Billing\LineItem;
use App\Data\Billing\SubscriptionState;
use App\Data\Billing\WebhookEvent;
use App\Exceptions\InvalidWebhook;
use App\Exceptions\PaymentProviderUnavailable;
use Carbon\CarbonInterface;

/** Stripe, behind an interface (implementation in app/Services/Billing; tests use a fake). */
interface PaymentProvider
{
    /**
     * Determine whether this environment has a payment provider configured; paid tiers can't be bought without one.
     *
     * @return bool
     */
    public function available(): bool;

    /**
     * Create the provider-side customer for an account and returns its ID. The account ID is stored on the customer as
     * metadata so webhooks can be traced back.
     *
     * @param  string  $accountId
     * @param  string  $name
     * @param  string  $email
     * @return string
     *
     * @throws PaymentProviderUnavailable
     */
    public function createCustomer(string $accountId, string $name, string $email): string;

    /**
     * Create a hosted checkout that creates the subscription with these items.
     *
     * @param  string  $customerId
     * @param  string  $accountId
     * @param  list<LineItem>  $items
     * @param  string  $successUrl
     * @param  string  $cancelUrl
     * @return string
     */
    public function checkoutUrl(string $customerId, string $accountId, array $items, string $successUrl, string $cancelUrl): string;

    /**
     * Make the subscription's items exactly these (adding, changing and removing items, prorated).
     *
     * @param  string  $subscriptionId
     * @param  list<LineItem>  $items
     * @return SubscriptionState
     */
    public function syncSubscription(string $subscriptionId, array $items): SubscriptionState;

    /**
     * Get the subscription's current state as the provider sees it (status, period and items), used to reconcile after
     * checkout and webhooks.
     *
     * @param  string  $subscriptionId
     * @return SubscriptionState
     */
    public function subscription(string $subscriptionId): SubscriptionState;

    /**
     * Cancel the subscription now, prorated. Used when an account's selections no longer bill anything, since a
     * subscription can't be left with zero items.
     *
     * @param  string  $subscriptionId
     * @return void
     */
    public function cancelSubscription(string $subscriptionId): void;

    /**
     * Create a one-time link to the provider's hosted billing portal, where the customer manages payment methods and
     * invoices, returning to `$returnUrl` when they're done.
     *
     * @param  string  $customerId
     * @param  string  $returnUrl
     * @return string
     */
    public function portalUrl(string $customerId, string $returnUrl): string;

    /**
     * Get the customer's most recent invoices, for the billing page.
     *
     * @param  string  $customerId
     * @param  int  $limit
     * @return list<InvoiceSummary> newest first
     */
    public function invoices(string $customerId, int $limit = 12): array;

    /**
     * Report metered usage (for example monitoring checks or analytics events) against a meter. `$idempotencyKey`
     * makes repeats of the same report harmless, so a retried job never bills twice.
     *
     * @param  string  $customerId
     * @param  string  $eventName
     * @param  int  $quantity
     * @param  CarbonInterface  $at
     * @param  string  $idempotencyKey
     * @return void
     */
    public function reportUsage(string $customerId, string $eventName, int $quantity, CarbonInterface $at, string $idempotencyKey): void;

    /**
     * Check a webhook's signature against the configured secret and parses it. Anything unsigned or tampered with
     * throws, so the handler only ever sees genuine events.
     *
     * @param  string  $payload
     * @param  string  $signature
     * @return WebhookEvent
     *
     * @throws InvalidWebhook
     */
    public function verifyWebhook(string $payload, string $signature): WebhookEvent;
}
