<?php

declare(strict_types=1);

namespace App\Platform\Catalog;

final readonly class Tier
{
    /**
     * One plan of a service.
     *
     * @param  string  $key  Stable identifier stored on billing items, e.g. `pro`.
     * @param  string  $name  Shown on the billing page.
     * @param  int|null  $monthlyCents  null while the price hasn't been set; such a paid tier can't be bought yet
     * @param  string  $description  One line on the billing page saying who the tier is for.
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

    /**
     * Whether the tier costs nothing, so choosing it needs no checkout. An unpriced tier (null) isn't free; it just
     * isn't on sale.
     *
     * @return bool
     */
    public function isFree(): bool
    {
        return $this->monthlyCents === 0;
    }
}
