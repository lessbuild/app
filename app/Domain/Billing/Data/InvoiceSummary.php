<?php

declare(strict_types=1);

namespace App\Domain\Billing\Data;

use Carbon\CarbonImmutable;

final readonly class InvoiceSummary
{
    public function __construct(
        public string $number,
        public int $totalCents,
        public string $currency,
        public string $status,
        public CarbonImmutable $date,
        public ?string $url,
    ) {}
}
