<?php

declare(strict_types=1);

namespace App\Queries\Analytics;

use App\Models\AnalyticsSite;
use App\Models\AnalyticsVisit;
use Carbon\CarbonImmutable;

/** How each tagged campaign did: visits that arrived through it, their pageviews and conversions. */
final class CampaignResultsQuery
{
    /**
     * Rank the site's campaigns over the last days by visits, with source and medium, pageviews, converting visits
     * and conversion rate.
     *
     * @param  AnalyticsSite  $site
     * @param  int  $days
     * @return list<array{campaign: string, source: string|null, medium: string|null, visits: int, pageviews: int, converted: int, rate: float}>
     */
    public function handle(AnalyticsSite $site, int $days = 30): array
    {
        $rows = AnalyticsVisit::query()->where('site_id', $site->id)->whereNotNull('entry_utm_campaign')
            ->where('started_at', '>=', CarbonImmutable::now('UTC')->subDays($days))
            ->toBase()
            ->selectRaw('entry_utm_campaign AS campaign, entry_utm_source AS source, entry_utm_medium AS medium')
            ->selectRaw('COUNT(*) AS visits, SUM(pageviews) AS pageviews, COUNT(CASE WHEN conversion_count > 0 THEN 1 END) AS converted')
            ->groupBy('entry_utm_campaign', 'entry_utm_source', 'entry_utm_medium')
            ->orderByDesc('visits')->limit(50)->get();

        return array_values($rows->map(fn (object $row): array => [
            'campaign' => (string) $row->campaign, 'source' => $row->source !== null ? (string) $row->source : null, 'medium' => $row->medium !== null ? (string) $row->medium : null,
            'visits' => (int) $row->visits, 'pageviews' => (int) $row->pageviews, 'converted' => (int) $row->converted,
            'rate' => (int) $row->visits > 0 ? round((int) $row->converted / (int) $row->visits * 100, 1) : 0.0,
        ])->all());
    }
}
