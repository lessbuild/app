<?php

namespace App\Modules\Deployer\Services;

/**
 * Normalizes Deployer's priced seat add-ons without treating the aggregate
 * subscription quantity as a seat count.
 */
final class DeployerSubscriptionSeatBilling
{
    /**
     * @param  iterable<mixed>  $items
     * @return array{additional_seats:?int,verified:bool,source:string}
     */
    public function fromStripeItems(iterable $items, ?string $planKey): array
    {
        return $this->project($items, $planKey, 'stripe_subscription_items');
    }

    /**
     * @param  iterable<mixed>  $items
     * @return array{additional_seats:?int,verified:bool,source:string}
     */
    public function fromCashierItems(
        iterable $items,
        ?string $planKey,
        ?string $subscriptionPriceId,
        mixed $subscriptionQuantity,
    ): array {
        $normalizedItems = [];
        foreach ($items as $item) {
            $normalizedItems[] = [
                'price_id' => data_get($item, 'stripe_price'),
                'quantity' => data_get($item, 'quantity'),
            ];
        }

        if ($subscriptionPriceId !== null && ! collect($normalizedItems)->contains(
            fn (array $item): bool => $item['price_id'] === $subscriptionPriceId,
        )) {
            $normalizedItems[] = [
                'price_id' => $subscriptionPriceId,
                'quantity' => $subscriptionQuantity ?? 1,
            ];
        }

        return $this->project($normalizedItems, $planKey, 'cashier_subscription_items');
    }

    /**
     * @param  iterable<mixed>  $items
     * @return array{additional_seats:?int,verified:bool,source:string}
     */
    private function project(iterable $items, ?string $planKey, string $source): array
    {
        $items = collect($items)->values()->all();
        $unknown = ['additional_seats' => null, 'verified' => false, 'source' => $source];
        $plan = $planKey === null ? null : config('billing.plans.'.$planKey);
        if (! is_array($plan)) {
            return $unknown;
        }

        $priceKinds = [];
        $this->registerPrice($priceKinds, $plan['price_id'] ?? null, 'base', 'monthly');
        $this->registerPrice($priceKinds, $plan['monthly_price_id'] ?? null, 'base', 'monthly');
        $this->registerPrice($priceKinds, $plan['yearly_price_id'] ?? null, 'base', 'yearly');
        $this->registerPrice($priceKinds, $plan['monthly_seat_price_id'] ?? null, 'seat', 'monthly');
        $this->registerPrice($priceKinds, $plan['yearly_seat_price_id'] ?? null, 'seat', 'yearly');

        $baseInterval = null;
        $baseCount = 0;
        $additionalSeats = 0;

        foreach ($items as $item) {
            $priceId = $this->priceId($item);
            $quantity = $this->quantity($item);
            $kind = $priceId === null ? null : ($priceKinds[$priceId] ?? null);

            if ($priceId === null || $quantity === null || ! is_array($kind)) {
                return $unknown;
            }

            if ($kind['type'] === 'base') {
                $baseCount++;
                $baseInterval = $kind['interval'];

                continue;
            }

            if ($kind['type'] !== 'seat' || $baseInterval !== null && $kind['interval'] !== $baseInterval) {
                return $unknown;
            }

            if ($additionalSeats > PHP_INT_MAX - $quantity) {
                return $unknown;
            }

            $additionalSeats += $quantity;
        }

        if ($baseCount !== 1 || $baseInterval === null) {
            return $unknown;
        }

        foreach ($items as $item) {
            $kind = $this->priceId($item) === null ? null : ($priceKinds[$this->priceId($item)] ?? null);
            if (is_array($kind) && $kind['type'] === 'seat' && $kind['interval'] !== $baseInterval) {
                return $unknown;
            }
        }

        return ['additional_seats' => $additionalSeats, 'verified' => true, 'source' => $source];
    }

    /** @param array<string,array{type:string,interval:string}|null> $priceKinds */
    private function registerPrice(array &$priceKinds, mixed $priceId, string $type, string $interval): void
    {
        if (! is_string($priceId) || trim($priceId) === '') {
            return;
        }

        if (array_key_exists($priceId, $priceKinds) && $priceKinds[$priceId] !== ['type' => $type, 'interval' => $interval]) {
            $priceKinds[$priceId] = null;

            return;
        }

        $priceKinds[$priceId] = ['type' => $type, 'interval' => $interval];
    }

    private function priceId(mixed $item): ?string
    {
        $value = data_get($item, 'price.id')
            ?? data_get($item, 'price_id')
            ?? data_get($item, 'stripe_price');

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function quantity(mixed $item): ?int
    {
        $value = data_get($item, 'quantity');
        if (! is_int($value) && (! is_string($value) || ! ctype_digit($value))) {
            return null;
        }

        $quantity = (int) $value;

        return $quantity >= 0 ? $quantity : null;
    }
}
