<?php

declare(strict_types=1);

namespace App\Queries\Analytics;

use App\Models\AnalyticsSite;
use Illuminate\Support\Facades\DB;

/** How much collection left out of a site's numbers lately, and why. */
final class FilteredVisitsQuery
{
    /**
     * Get the totals per reason over the last 30 days.
     *
     * @param  AnalyticsSite  $site
     * @return array<string, int>
     */
    public function handle(AnalyticsSite $site): array
    {
        $totals = [];
        foreach (DB::table('analytics_filtered_counts')->where('site_id', $site->id)->where('date', '>=', now()->subDays(30)->toDateString())
            ->selectRaw('reason, SUM(count) AS total')->groupBy('reason')->get() as $row) {
            $totals[(string) $row->reason] = (int) $row->total;
        }

        return $totals;
    }
}
