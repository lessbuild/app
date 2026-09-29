<?php

declare(strict_types=1);

namespace App\Queries\Analytics;

use App\Models\AnalyticsGoalConversion;
use App\Models\AnalyticsSite;
use App\Models\AnalyticsVisit;
use App\Support\Analytics\Revenue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/** How each tagged campaign did: visits that arrived through it, their pageviews, conversions and revenue. */
final class CampaignResultsQuery
{
    /**
     * Rank the site's campaigns over the last days by visits, with source and medium, pageviews, converting visits
     * and conversion rate.
     *
     * @param  AnalyticsSite  $site
     * @param  int  $days
     * @return list<array{campaign: string, source: string|null, medium: string|null, visits: int, pageviews: int, converted: int, rate: float, revenue: string|null}>
     */
    public function handle(AnalyticsSite $site, int $days = 30): array
    {
        $revenue = $this->revenue($site, CarbonImmutable::now('UTC')->subDays($days));
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
            'revenue' => Revenue::format($revenue[$row->campaign.'|'.$row->source.'|'.$row->medium] ?? []),
        ])->all());
    }

    /**
     * Total the revenue of goal completions per campaign (with source and medium) and currency. Each event counts
     * once, even when it completes several goals; it belongs to the visit of the same session or visitor whose time
     * span includes it.
     *
     * @param  AnalyticsSite  $site
     * @param  CarbonImmutable  $since
     * @return array<string, array<string, float>> keyed by "campaign|source|medium", then currency
     */
    private function revenue(AnalyticsSite $site, CarbonImmutable $since): array
    {
        $rows = DB::table('analytics_events as e')
            ->join('analytics_visits as v', function (JoinClause $join): void {
                $join->on('v.site_id', '=', 'e.site_id')
                    ->whereColumn('e.occurred_at', '>=', 'v.started_at')
                    ->whereColumn('e.occurred_at', '<=', 'v.last_seen_at')
                    ->where(fn (QueryBuilder $identity) => $identity->whereColumn('v.session_id', 'e.session_id')
                        ->orWhere(fn (QueryBuilder $byVisitor) => $byVisitor->whereNull('v.session_id')->whereColumn('v.visitor_hash', 'e.visitor_hash')));
            })
            ->where('e.site_id', $site->id)
            ->where('e.type', 'event')
            ->where('e.occurred_at', '>=', $since)
            ->whereNotNull('v.entry_utm_campaign')
            ->whereIn('e.id', AnalyticsGoalConversion::query()->where('site_id', $site->id)->select('analytics_event_id'))
            ->whereRaw(Revenue::amount('e').' IS NOT NULL')
            ->selectRaw('v.entry_utm_campaign AS campaign, v.entry_utm_source AS source, v.entry_utm_medium AS medium, '.Revenue::currency('e').' AS currency, SUM('.Revenue::amount('e').') AS amount')
            ->groupBy('v.entry_utm_campaign', 'v.entry_utm_source', 'v.entry_utm_medium', 'currency')
            ->get();
        $totals = [];
        foreach ($rows as $row) {
            $totals[$row->campaign.'|'.$row->source.'|'.$row->medium][(string) $row->currency] = (float) $row->amount;
        }

        return $totals;
    }
}
