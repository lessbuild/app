<?php

declare(strict_types=1);

namespace App\Data\Billing;

use Carbon\CarbonImmutable;

final readonly class BillingOverview
{
    /** @param list<ServiceBillingCard> $services */
    public function __construct(
        public array $services,
        public int $monthlyTotalCents,
        public string $currency,
        public string $status,
        public ?CarbonImmutable $periodEnd,
        public bool $hasCustomer,
        public bool $paymentsAvailable,
    ) {}
}
