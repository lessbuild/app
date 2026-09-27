<?php

declare(strict_types=1);

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Catalog\Tier;
use App\Domain\Billing\Enums\SelectionKind;
use Illuminate\Contracts\Config\Repository;

/** Stripe price IDs for catalogue items, from config/billing.php. */
final class PriceBook
{
    public function __construct(private readonly Repository $config) {}

    public function priceId(string $service, SelectionKind $kind, string $itemKey): ?string
    {
        $id = $this->config->get("billing.prices.{$service}.{$kind->value}.{$itemKey}");

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** Free tiers are always available; paid ones need an amount and a Stripe price. */
    public function purchasable(string $service, Tier $tier): bool
    {
        return $tier->isFree() || ($tier->monthlyCents !== null && $this->priceId($service, SelectionKind::Tier, $tier->key) !== null);
    }

    public function currency(): string
    {
        return (string) $this->config->get('billing.currency', 'usd');
    }
}
