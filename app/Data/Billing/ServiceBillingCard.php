<?php

declare(strict_types=1);

namespace App\Data\Billing;

use App\Platform\Catalog\Tier;
use Carbon\CarbonImmutable;

final readonly class ServiceBillingCard
{
    /**
     * Create a new ServiceBillingCard instance.
     *
     * One service on the billing page.
     *
     * @param  string  $key  The service's key.
     * @param  string  $name  The service's name.
     * @param  string  $icon  The service's icon.
     * @param  Tier  $tier  The tier the account is on.
     * @param  ?CarbonImmutable  $endsAt  When a scheduled downgrade takes effect, if one is pending.
     * @param  bool  $inUse  Whether any project uses the service, which the page uses to explain charges.
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
