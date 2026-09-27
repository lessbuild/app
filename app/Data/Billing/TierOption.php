<?php

declare(strict_types=1);

namespace App\Data\Billing;

use App\Platform\Catalog\Tier;

final readonly class TierOption
{
    /**
     * One tier a service's plan picker offers.
     *
     * @param  Tier  $tier  The tier.
     * @param  bool  $current  Whether the account is on it now.
     * @param  bool  $purchasable  Whether it can be chosen: free, or priced with payments available.
     */
    public function __construct(
        public Tier $tier,
        public bool $current,
        public bool $purchasable,
    ) {}
}
