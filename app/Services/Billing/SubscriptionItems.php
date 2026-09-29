<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Contracts\PaymentProvider;
use App\Data\Billing\LineItem;
use App\Enums\SelectionKind;
use App\Models\BillingAccount;
use App\Models\BillingSelection;

/** Turns an account's paid selections into subscription line items and writes Stripe's item ids back. */
final class SubscriptionItems
{
    /**
     * Create a new SubscriptionItems instance.
     *
     * Keeps the subscription's items in step with the account's selections.
     *
     * @param  PriceBook  $prices  The price for each selection.
     * @param  PaymentProvider  $provider  Changes or cancels the subscription.
     */
    public function __construct(private readonly PriceBook $prices, private readonly PaymentProvider $provider) {}

    /**
     * Build the `service:kind:item` reference stored on a subscription item.
     *
     * @param  string  $service
     * @param  SelectionKind  $kind
     * @param  string  $itemKey
     * @return string
     */
    public static function reference(string $service, SelectionKind $kind, string $itemKey): string
    {
        return "{$service}:{$kind->value}:{$itemKey}";
    }

    /**
     * Split a reference back into service, kind and item, or null when it isn't one of ours.
     *
     * @param  string  $reference
     * @return array{0: string, 1: SelectionKind, 2: string}|null
     */
    public static function parse(string $reference): ?array
    {
        $parts = explode(':', $reference, 3);
        $kind = count($parts) === 3 ? SelectionKind::tryFrom($parts[1]) : null;

        return $kind !== null ? [$parts[0], $kind, $parts[2]] : null;
    }

    /**
     * Get the line items the account should be paying for: each selection with a price (imported selections keep their
     * original price).
     *
     * @param  string  $accountId
     * @return list<LineItem> every selection that is billed (including ones running out at period end), at the account's interval
     */
    public function desired(string $accountId): array
    {
        $items = [];
        $interval = (string) (BillingAccount::query()->whereKey($accountId)->value('interval') ?? 'month');
        foreach (BillingSelection::query()->where('account_id', $accountId)->orderBy('service')->get() as $selection) {
            $price = $selection->legacy_price_id ?? $this->prices->priceId($selection->service, $selection->kind, $selection->item_key, $interval);
            if ($price !== null) {
                $items[] = new LineItem(self::reference($selection->service, $selection->kind, $selection->item_key), $price, $selection->quantity);
            }
        }

        return $items;
    }

    /**
     * Push the selections to the live subscription; cancels it when nothing is billed any more.
     *
     * @param  BillingAccount  $billing
     * @return void
     */
    public function sync(BillingAccount $billing): void
    {
        if (! $billing->hasLiveSubscription() || $billing->stripe_subscription_id === null) {
            return;
        }

        $items = $this->desired($billing->account_id);
        if ($items === []) {
            $this->provider->cancelSubscription($billing->stripe_subscription_id);
            $billing->forceFill(['status' => 'canceled', 'stripe_subscription_id' => null, 'current_period_end' => null])->save();

            return;
        }

        $state = $this->provider->syncSubscription($billing->stripe_subscription_id, $items);
        $billing->forceFill(['status' => $state->status, 'current_period_end' => $state->currentPeriodEnd])->save();
        $this->storeItemIds($billing->account_id, $state->itemIds);
    }

    /**
     * Store the provider's item ID on each selection, so later changes update items in place.
     *
     * @param  string  $accountId
     * @param  array<string, string>  $itemIds
     * @return void
     */
    public function storeItemIds(string $accountId, array $itemIds): void
    {
        foreach ($itemIds as $reference => $itemId) {
            $parsed = self::parse($reference);
            if ($parsed !== null) {
                BillingSelection::query()->where('account_id', $accountId)->where('service', $parsed[0])->where('kind', $parsed[1])->where('item_key', $parsed[2])
                    ->update(['stripe_item_id' => $itemId]);
            }
        }
    }
}
