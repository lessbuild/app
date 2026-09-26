<?php

namespace App\Core\Data\Billing;

final readonly class ProductUsageMeter
{
    public function __construct(
        public string $key,
        public string $label,
        public int $used,
    ) {}
}
