<?php

declare(strict_types=1);

namespace App\Data\Billing;

use Carbon\CarbonImmutable;

final readonly class BillingOverview
{
    /**
     * Create a new BillingOverview instance.
     *
     * Everything the billing page shows.
     *
     * @param  list<ServiceBillingCard>  $services
     * @param  int  $monthlyTotalCents  What the chosen tiers and add-ons cost per month, before usage.
     * @param  string  $currency  The currency prices are in.
     * @param  string  $status  The subscription's status, or `none` before anything paid was chosen.
     * @param  ?CarbonImmutable  $periodEnd  When the current paid period ends.
     * @param  bool  $hasCustomer  Whether the account has a customer at the payment provider, which the billing portal
     *                             needs.
     * @param  bool  $paymentsAvailable  Whether payments are configured in this environment.
     */
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
