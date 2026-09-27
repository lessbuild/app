<?php

declare(strict_types=1);

namespace App\Domain\Billing\Data;

final readonly class MeterUsage
{
    public function __construct(
        public string $name,
        public string $unit,
        public int $used,
        public ?int $allowance,
    ) {}
}
