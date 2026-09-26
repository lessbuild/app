<?php

namespace App\Core\Data\Billing;

final readonly class ProductUsageSummary
{
    /** @param list<ProductUsageMeter> $meters */
    public function __construct(
        public string $periodLabel,
        public array $meters,
    ) {}
}
