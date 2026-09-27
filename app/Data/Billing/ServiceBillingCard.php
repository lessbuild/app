<?php

declare(strict_types=1);

namespace App\Data\Billing;

use App\Platform\Catalog\Tier;
use Carbon\CarbonImmutable;

final readonly class ServiceBillingCard
{
    /**
     * @param  list<TierOption>  $options
     * @param  list<MeterUsage>  $meters
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $icon,
        public Tier $tier,
        public ?CarbonImmutable $endsAt,
        public bool $inUse,
        public array $options,
        public array $meters,
    ) {}
}
