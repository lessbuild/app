<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Contracts\PaymentProvider;
use App\Data\Billing\InvoiceSummary;
use App\Data\Billing\LineItem;
use App\Data\Billing\SubscriptionState;
use App\Data\Billing\WebhookEvent;
use App\Exceptions\InvalidWebhook;
use App\Exceptions\PaymentProviderUnavailable;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Subscription;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Items carry their `service:kind:item` reference in metadata. Checkout can't set item metadata, so the
 * subscription's metadata also maps price => reference, which is how items created by Checkout are recognised.
 */
final class StripePaymentProvider implements PaymentProvider
{
    private const REFERENCES = 'buildpusher_references';

    public function __construct(private readonly StripeClient $stripe, private readonly ?string $webhookSecret) {}

    public function available(): bool
    {
        return true;
    }

    public function createCustomer(string $accountId, string $name, string $email): string
    {
        return $this->call(fn (): string => $this->stripe->customers->create([
            'name' => $name,
            'email' => $email,
            'metadata' => ['account_id' => $accountId],
        ])->id);
    }

    public function checkoutUrl(string $customerId, string $accountId, array $items, string $successUrl, string $cancelUrl): string
    {
        return $this->call(fn (): string => (string) $this->stripe->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => $customerId,
            'client_reference_id' => $accountId,
            'line_items' => array_map(fn (LineItem $item): array => ['price' => $item->priceId, 'quantity' => $item->quantity], $items),
            'subscription_data' => ['metadata' => ['account_id' => $accountId, self::REFERENCES => $this->referenceMap($items)]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ])->url);
    }

    public function syncSubscription(string $subscriptionId, array $items): SubscriptionState
    {
        return $this->call(function () use ($subscriptionId, $items): SubscriptionState {
            $subscription = $this->stripe->subscriptions->retrieve($subscriptionId, ['expand' => ['items']]);
            // Match by slot, so a service moving from Pro to Team updates its one item in place.
            $existing = [];
            foreach ($this->itemsByReference($subscription) as $reference => $itemId) {
                $existing[self::slot($reference)] = $itemId;
            }
            $wanted = [];
            $changes = [];
            foreach ($items as $item) {
                $slot = self::slot($item->reference);
                $wanted[$slot] = true;
                $change = ['price' => $item->priceId, 'quantity' => $item->quantity, 'metadata' => ['reference' => $item->reference]];
                $changes[] = isset($existing[$slot]) ? ['id' => $existing[$slot]] + $change : $change;
            }
            foreach ($existing as $slot => $itemId) {
                if (! isset($wanted[$slot])) {
                    $changes[] = ['id' => $itemId, 'deleted' => true];
                }
            }

            $updated = $this->stripe->subscriptions->update($subscriptionId, [
                'items' => $changes,
                'proration_behavior' => 'create_prorations',
                'metadata' => [self::REFERENCES => $this->referenceMap($items)],
                'expand' => ['items'],
            ]);

            return $this->state($updated);
        });
    }

    public function subscription(string $subscriptionId): SubscriptionState
    {
        return $this->call(fn (): SubscriptionState => $this->state($this->stripe->subscriptions->retrieve($subscriptionId, ['expand' => ['items']])));
    }

    public function cancelSubscription(string $subscriptionId): void
    {
        $this->call(fn () => $this->stripe->subscriptions->cancel($subscriptionId, ['prorate' => true]));
    }

    public function portalUrl(string $customerId, string $returnUrl): string
    {
        return $this->call(fn (): string => $this->stripe->billingPortal->sessions->create(['customer' => $customerId, 'return_url' => $returnUrl])->url);
    }

    public function invoices(string $customerId, int $limit = 12): array
    {
        return $this->call(function () use ($customerId, $limit): array {
            $invoices = [];
            foreach ($this->stripe->invoices->all(['customer' => $customerId, 'limit' => $limit])->data as $invoice) {
                $invoices[] = new InvoiceSummary(
                    number: (string) ($invoice->number ?? $invoice->id),
                    totalCents: (int) $invoice->total,
                    currency: (string) $invoice->currency,
                    status: (string) $invoice->status,
                    date: CarbonImmutable::createFromTimestamp((int) $invoice->created),
                    url: is_string($invoice->hosted_invoice_url) ? $invoice->hosted_invoice_url : null,
                );
            }

            return $invoices;
        });
    }

    public function reportUsage(string $customerId, string $eventName, int $quantity, CarbonInterface $at, string $idempotencyKey): void
    {
        $this->call(fn () => $this->stripe->billing->meterEvents->create([
            'event_name' => $eventName,
            'identifier' => $idempotencyKey,
            'timestamp' => $at->getTimestamp(),
            'payload' => ['stripe_customer_id' => $customerId, 'value' => (string) $quantity],
        ]));
    }

    public function verifyWebhook(string $payload, string $signature): WebhookEvent
    {
        if ($this->webhookSecret === null || $this->webhookSecret === '') {
            throw new InvalidWebhook('No webhook secret is configured.');
        }
        try {
            $event = Webhook::constructEvent($payload, $signature, $this->webhookSecret);
        } catch (SignatureVerificationException|UnexpectedValueException $exception) {
            throw new InvalidWebhook($exception->getMessage(), previous: $exception);
        }
        $object = $event->data->object->toArray();

        return new WebhookEvent((string) $event->id, (string) $event->type, $object);
    }

    /** A service's tier occupies one item whichever tier it is; each add-on has its own. */
    private static function slot(string $reference): string
    {
        $parts = explode(':', $reference, 3);

        return ($parts[1] ?? null) === 'tier' ? $parts[0].':tier' : $reference;
    }

    /** @param list<LineItem> $items */
    private function referenceMap(array $items): string
    {
        $map = [];
        foreach ($items as $item) {
            $map[$item->priceId] = $item->reference;
        }

        return (string) json_encode($map);
    }

    /** @return array<string, string> reference => item id */
    private function itemsByReference(Subscription $subscription): array
    {
        $map = json_decode((string) ($subscription->metadata[self::REFERENCES] ?? '{}'), true);
        $map = is_array($map) ? $map : [];
        $items = [];
        foreach ($subscription->items->data as $item) {
            $reference = $item->metadata['reference'] ?? ($map[$item->price->id] ?? null);
            if (is_string($reference)) {
                $items[$reference] = (string) $item->id;
            }
        }

        return $items;
    }

    private function state(Subscription $subscription): SubscriptionState
    {
        $end = $subscription->items->data[0]->current_period_end ?? null;

        return new SubscriptionState(
            status: (string) $subscription->status,
            currentPeriodEnd: is_int($end) ? CarbonImmutable::createFromTimestamp($end) : null,
            itemIds: $this->itemsByReference($subscription),
        );
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $request
     * @return T
     */
    private function call(Closure $request): mixed
    {
        try {
            return $request();
        } catch (ApiErrorException $exception) {
            report($exception);

            throw new PaymentProviderUnavailable(__('The payment provider couldn’t complete that. Please try again.'), previous: $exception);
        }
    }
}
