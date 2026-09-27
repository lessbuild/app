<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\AnalyticsSite;

final class RebuildSiteReports
{
    /**
     * Rebuilds everything derived from a site's events, in dependency order.
     *
     * @param  RebuildSiteVisits  $visits  Rebuilds visits first.
     * @param  RebuildGoalConversions  $conversions  Then conversions, which point at visits.
     * @param  RebuildReportAggregates  $aggregates  Then daily totals, which count both.
     */
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
