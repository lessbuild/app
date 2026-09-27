<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Contracts\PaymentProvider;
use App\Data\Billing\InvoiceSummary;
use App\Data\Billing\LineItem;
use App\Data\Billing\SubscriptionState;
use App\Data\Billing\WebhookEvent;
use App\Exceptions\InvalidWebhook;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Records what would be sent to Stripe and keeps one in-memory subscription per id. */
final class FakePaymentProvider implements PaymentProvider
{
    /** @var array<string, list<LineItem>> subscription id => items */
    public array $subscriptions = [];

    /** @var list<array{customer: string, items: list<LineItem>}> */
    public array $checkouts = [];

    /** @var list<string> */
    public array $cancelled = [];

    /** @var list<array{event: string, quantity: int, key: string}> */
    public array $usage = [];

    /** @var list<InvoiceSummary> */
    public array $invoiceList = [];

    public function available(): bool
    {
        return true;
    }

    public function createCustomer(string $accountId, string $name, string $email): string
    {
        return 'cus_'.$accountId;
    }

    public function checkoutUrl(string $customerId, string $accountId, array $items, string $successUrl, string $cancelUrl): string
    {
        $this->checkouts[] = ['customer' => $customerId, 'items' => $items];

        return 'https://checkout.stripe.test/session';
    }

    /** What Stripe would do once the person pays: create the subscription from the checkout's items. */
    public function completeCheckout(string $subscriptionId): void
    {
        $checkout = end($this->checkouts);
        $this->subscriptions[$subscriptionId] = $checkout === false ? [] : $checkout['items'];
    }

    public function syncSubscription(string $subscriptionId, array $items): SubscriptionState
    {
        $this->subscriptions[$subscriptionId] = $items;

        return $this->subscription($subscriptionId);
    }

    public function subscription(string $subscriptionId): SubscriptionState
    {
        $ids = [];
        foreach ($this->subscriptions[$subscriptionId] ?? [] as $item) {
            $ids[$item->reference] = 'si_'.md5($item->reference);
        }

        return new SubscriptionState('active', CarbonImmutable::now()->addMonth(), $ids);
    }

    public function cancelSubscription(string $subscriptionId): void
    {
        $this->cancelled[] = $subscriptionId;
        unset($this->subscriptions[$subscriptionId]);
    }

    public function portalUrl(string $customerId, string $returnUrl): string
    {
        return 'https://billing.stripe.test/portal';
    }

    public function invoices(string $customerId, int $limit = 12): array
    {
        return $this->invoiceList;
    }

    public function reportUsage(string $customerId, string $eventName, int $quantity, CarbonInterface $at, string $idempotencyKey): void
    {
        $this->usage[] = ['event' => $eventName, 'quantity' => $quantity, 'key' => $idempotencyKey];
    }

    public function verifyWebhook(string $payload, string $signature): WebhookEvent
    {
        if ($signature !== 'valid') {
            throw new InvalidWebhook('bad signature');
        }
        $event = json_decode($payload, true);

        return new WebhookEvent($event['id'], $event['type'], $event['data']['object']);
    }
}
