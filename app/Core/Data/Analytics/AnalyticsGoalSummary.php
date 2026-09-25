<?php

namespace App\Core\Data\Analytics;

final readonly class AnalyticsGoalSummary
{
    public function __construct(
        public string $id,
        public string $name,
        public string $kind,
        public string $matchType,
        public string $matchValue,
        public bool $active,
    ) {}
}
