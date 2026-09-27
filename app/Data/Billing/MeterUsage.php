<?php

declare(strict_types=1);

namespace App\Data\Billing;

final readonly class MeterUsage
{
    /**
     * This month's usage of one meter.
     *
     * @param  string  $name  The meter's name.
     * @param  string  $unit  What is counted.
     * @param  int  $used  How much has been used so far this month.
     * @param  ?int  $allowance  How much the tier includes; null when it's unlimited.
     */
    public function __construct(
        public string $name,
        public string $unit,
        public int $used,
        public ?int $allowance,
    ) {}
}
