<?php

declare(strict_types=1);

namespace App\Domain\Billing\Catalog;

final readonly class Tier
{
    /**
     * @param  int|null  $monthlyCents  null while the price hasn't been set; such a paid tier can't be bought yet
     * @param  list<string>  $features  what people read on the billing page
     * @param  array<string, int|null>  $limits  entitlement key => limit (null = unlimited)
     * @param  list<string>  $flags  boolean entitlements this tier turns on
     */
    public function __construct(
        public string $key,
        public string $name,
        public ?int $monthlyCents,
        public string $description,
        public array $features = [],
        public array $limits = [],
        public array $flags = [],
    ) {}

    public function isFree(): bool
    {
        return $this->monthlyCents === 0;
    }
}
