<?php

declare(strict_types=1);

namespace App\Platform\Catalog;

/** What a service sells: its tiers (first one is the free default), add-ons and meters. */
final readonly class ServiceBilling
{
    /**
     * Builds a service's catalogue. The first tier is the one every project starts on.
     *
     * @param  list<Tier>  $tiers
     * @param  list<AddOn>  $addOns
     * @param  list<Meter>  $meters
     */
    public function __construct(
        public array $tiers,
        public array $addOns = [],
        public array $meters = [],
    ) {}

    /**
     * The tier a project is on until someone chooses another: the first, which is free.
     */
    public function defaultTier(): Tier
    {
        return $this->tiers[0];
    }

    /**
     * Finds a tier by key, or null when the service doesn't sell one with that key.
     */
    public function tier(string $key): ?Tier
    {
        foreach ($this->tiers as $tier) {
            if ($tier->key === $key) {
                return $tier;
            }
        }

        return null;
    }

    /**
     * Finds an add-on by key, or null when the service doesn't sell one with that key.
     */
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
