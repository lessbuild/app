<?php

declare(strict_types=1);

namespace App\Data\Billing;

use App\Platform\Catalog\Tier;

final readonly class TierOption
{
    public function __construct(
        public Tier $tier,
        public bool $current,
        public bool $purchasable,
    ) {}
}
