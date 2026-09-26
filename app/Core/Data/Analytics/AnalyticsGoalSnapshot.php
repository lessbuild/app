<?php

namespace App\Core\Data\Analytics;

final readonly class AnalyticsGoalSnapshot
{
    /** @param list<AnalyticsGoalSummary> $goals */
    public function __construct(
        public string $siteId,
        public string $siteName,
        public array $goals,
        public bool $canManage,
        public bool $available = true,
    ) {}
}
