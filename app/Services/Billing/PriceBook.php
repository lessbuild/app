<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\SelectionKind;
use App\Platform\Catalog\Tier;
use Illuminate\Contracts\Config\Repository;

/** Stripe price IDs for catalogue items, from config/billing.php. */
final class PriceBook
{
    /**
     * Create a new PriceBook instance.
     *
     * Reads prices from config/billing.php.
     *
     * @param  Repository  $config  The configuration.
     */
    public function __construct(private readonly Repository $config) {}

    /**
     * Get the Stripe price for a catalogue item, or null when it hasn't been set (which keeps it off sale).
     *
     * @param  string  $service
     * @param  SelectionKind  $kind
     * @param  string  $itemKey
     * @return string|null
     */
    public function priceId(string $service, SelectionKind $kind, string $itemKey): ?string
    {
        $id = $this->config->get("billing.prices.{$service}.{$kind->value}.{$itemKey}");

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Determine whether a tier can be chosen: free tiers always can; paid ones need an amount and a Stripe price.
     *
     * @param  string  $service
     * @param  Tier  $tier
     * @return bool
     */
    public function purchasable(string $service, Tier $tier): bool
    {
        return $tier->isFree() || ($tier->monthlyCents !== null && $this->priceId($service, SelectionKind::Tier, $tier->key) !== null);
    }

    /**
     * Get the currency prices are in.
     *
     * @return string
     */
    public function currency(): string
    {
        return (string) $this->config->get('billing.currency', 'usd');
    }
}
