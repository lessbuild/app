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
     * Get the Stripe price for a catalogue item, monthly or yearly, or null when it hasn't been set (which keeps it
     * off sale at that interval). Usage prices are keyed by the meter's name within its service ("monitoring.events"
     * reads prices.monitoring.usage.events) and are monthly only, since Stripe bills metered usage monthly and a
     * subscription's items share one interval.
     *
     * @param  string  $service
     * @param  SelectionKind  $kind
     * @param  string  $itemKey
     * @param  string  $interval  month or year
     * @return string|null
     */
    public function priceId(string $service, SelectionKind $kind, string $itemKey, string $interval = 'month'): ?string
    {
        $prices = $interval === 'year' ? 'prices_yearly' : 'prices';
        if ($kind === SelectionKind::Usage) {
            if ($interval === 'year') {
                return null;
            }
            $itemKey = str_starts_with($itemKey, "{$service}.") ? substr($itemKey, strlen($service) + 1) : $itemKey;
        }
        $id = $this->config->get("billing.{$prices}.{$service}.{$kind->value}.{$itemKey}");

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Determine whether a tier can be chosen at an interval: free tiers always can; paid ones need an amount and a
     * Stripe price for that interval.
     *
     * @param  string  $service
     * @param  Tier  $tier
     * @param  string  $interval  month or year
     * @return bool
     */
    public function purchasable(string $service, Tier $tier, string $interval = 'month'): bool
    {
        return $tier->isFree() || ($tier->monthlyCents !== null && $this->priceId($service, SelectionKind::Tier, $tier->key, $interval) !== null);
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
