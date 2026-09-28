<?php

declare(strict_types=1);

namespace App\Data\Billing;

use Carbon\CarbonImmutable;

final readonly class SubscriptionState
{
    /**
     * Create a new SubscriptionState instance.
     *
     * A subscription as the payment provider reports it.
     *
     * @param  string  $status  The provider's status, such as `active` or `past_due`.
     * @param  ?CarbonImmutable  $currentPeriodEnd  When the current period ends.
     * @param  array<string, string>  $itemIds  line item reference => Stripe subscription item id
     */
    public function __construct(
        public string $status,
        public ?CarbonImmutable $currentPeriodEnd,
        public array $itemIds,
    ) {}
}
