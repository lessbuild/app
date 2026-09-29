<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\AnalyticsSite;
use Carbon\CarbonImmutable;

final class RefreshRecentAggregates
{
    /**
     * Create a new RefreshRecentAggregates instance.
     *
     * @param  RebuildReportAggregates  $aggregates  Rebuilds the days.
     */
    public function __construct(private readonly RebuildReportAggregates $aggregates) {}

    /**
     * Rebuild today's and yesterday's daily totals (in each site's timezone) for every site that received events in
     * the last two days. Runs every ten minutes, so batches don't have to redo the busiest days each minute.
     *
     * @return int how many sites were refreshed
     */
    public function handle(): int
    {
        $refreshed = 0;
        AnalyticsSite::query()->where('last_event_at', '>=', now()->subDays(RebuildReportAggregates::RECENT_DAYS + 1))
            ->orderBy('id')
            ->each(function (AnalyticsSite $site) use (&$refreshed): void {
                $today = CarbonImmutable::now($site->timezone);
                $dates = [];
                for ($day = 0; $day < RebuildReportAggregates::RECENT_DAYS; $day++) {
                    $dates[] = $today->subDays($day)->toDateString();
                }
                $this->aggregates->handle($site, null, $dates);
                $refreshed++;
            });

        return $refreshed;
    }
}
