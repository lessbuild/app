<?php

namespace App\Modules\Analytics\Exceptions;

use Carbon\CarbonImmutable;
use RuntimeException;

final class MonthlyEventLimitReached extends RuntimeException
{
    public function __construct(
        public readonly int $used,
        public readonly int $limit,
        public readonly CarbonImmutable $periodStart,
        public readonly CarbonImmutable $retryAt,
    ) {
        parent::__construct('The Analytics workspace has reached its monthly accepted-event allowance.');
    }
}
