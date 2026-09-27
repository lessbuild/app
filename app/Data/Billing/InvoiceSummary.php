<?php

declare(strict_types=1);

namespace App\Data\Billing;

use Carbon\CarbonImmutable;

final readonly class InvoiceSummary
{
    /**
     * One invoice on the billing page.
     *
     * @param  string  $number  The invoice number.
     * @param  int  $totalCents  The total, in the currency's minor unit.
     * @param  string  $currency  The three-letter currency code.
     * @param  string  $status  The provider's status, such as `paid` or `open`.
     * @param  CarbonImmutable  $date  When it was issued.
     * @param  ?string  $url  The provider's hosted page for the invoice, when there is one.
     */
    public function __construct(
        public string $number,
        public int $totalCents,
        public string $currency,
        public string $status,
        public CarbonImmutable $date,
        public ?string $url,
    ) {}
}
