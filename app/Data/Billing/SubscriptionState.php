<?php

declare(strict_types=1);

namespace App\Data\Billing;

use Carbon\CarbonImmutable;

final readonly class SubscriptionState
{
    /** @param array<string, string> $itemIds line item reference => Stripe subscription item id */
    public function __construct(
        public string $status,
        public ?CarbonImmutable $currentPeriodEnd,
        public array $itemIds,
    ) {}
}
