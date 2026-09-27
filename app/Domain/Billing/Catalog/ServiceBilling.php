<?php

declare(strict_types=1);

namespace App\Domain\Billing\Catalog;

/** What a service sells: its tiers (first one is the free default), add-ons and meters. */
final readonly class ServiceBilling
{
    /**
     * @param  list<Tier>  $tiers
     * @param  list<AddOn>  $addOns
     * @param  list<Meter>  $meters
     */
    public function __construct(
        public array $tiers,
        public array $addOns = [],
        public array $meters = [],
    ) {}

    public function defaultTier(): Tier
    {
        return $this->tiers[0];
    }

    public function tier(string $key): ?Tier
    {
        foreach ($this->tiers as $tier) {
            if ($tier->key === $key) {
                return $tier;
            }
        }

        return null;
    }

    public function addOn(string $key): ?AddOn
    {
        foreach ($this->addOns as $addOn) {
            if ($addOn->key === $key) {
                return $addOn;
            }
        }

        return null;
    }
}
