<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\AnalyticsSite;

final class RebuildSiteReports
{
    public function __construct(
        private readonly RebuildSiteVisits $visits,
        private readonly RebuildGoalConversions $conversions,
        private readonly RebuildReportAggregates $aggregates,
    ) {}

    /** Recompute visits, goal conversions and daily reports for the whole site (after a goal changes). */
    public function handle(AnalyticsSite $site): void
    {
        $this->visits->handle($site);
        $this->conversions->handle($site);
        $this->aggregates->handle($site);
    }
}
