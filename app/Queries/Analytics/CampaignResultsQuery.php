<?php

declare(strict_types=1);

namespace App\Queries\Analytics;

use App\Models\AnalyticsAdSpend;
use App\Models\AnalyticsGoalConversion;
use App\Models\AnalyticsSite;
use App\Models\AnalyticsVisit;
use App\Support\Analytics\Revenue;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * How each tagged campaign did: visits that arrived through it, their pageviews, conversions and revenue, and, with
 * imported ad spend, what it cost, the cost per converting visit and the return on ad spend.
 */
final class CampaignResultsQuery
{
    /**
     * Rank the site's campaigns over the last days by visits, with source and medium, pageviews, converting visits
     * and conversion rate. Imported spend is matched on campaign and source, ignoring case, and shown on the
     * campaign's busiest row; campaigns with spend but no visits are listed after the rest. Return on ad spend needs
     * the revenue and the spend in the same currency.
     *
     * @param  AnalyticsSite  $site
     * @param  int  $days
     * @return list<array{campaign: string, source: string|null, medium: string|null, visits: int, pageviews: int, converted: int, rate: float, revenue: string|null, cost: string|null, cost_per_conversion: string|null, roas: float|null}>
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

        $spend = $this->spend($site, CarbonImmutable::now($site->timezone)->subDays($days)->toDateString());
        $results = [];
        foreach ($rows as $row) {
            $key = mb_strtolower((string) $row->campaign).'|'.mb_strtolower((string) $row->source);
            $amounts = $revenue[$row->campaign.'|'.$row->source.'|'.$row->medium] ?? [];
            $cost = $spend[$key] ?? null;
            unset($spend[$key]);
            $results[] = [
                'campaign' => (string) $row->campaign, 'source' => $row->source !== null ? (string) $row->source : null, 'medium' => $row->medium !== null ? (string) $row->medium : null,
                'visits' => (int) $row->visits, 'pageviews' => (int) $row->pageviews, 'converted' => (int) $row->converted,
                'rate' => (int) $row->visits > 0 ? round((int) $row->converted / (int) $row->visits * 100, 1) : 0.0,
                'revenue' => Revenue::format($amounts),
                ...$this->costs($cost, (int) $row->converted, $amounts),
            ];
        }
        foreach ($spend as $cost) {
            $results[] = ['campaign' => $cost['campaign'], 'source' => $cost['source'], 'medium' => null, 'visits' => 0, 'pageviews' => 0, 'converted' => 0, 'rate' => 0.0, 'revenue' => null, ...$this->costs($cost, 0, [])];
        }

        return $results;
    }

    /**
     * Total the imported ad spend since a date per campaign and source (lower case), in each currency.
     *
     * @param  AnalyticsSite  $site
     * @param  string  $since  a date in the site's timezone
     * @return array<string, array{campaign: string, source: string, amounts: array<string, float>}>
     */
    private function spend(AnalyticsSite $site, string $since): array
    {
        $totals = [];
        $rows = AnalyticsAdSpend::query()->where('site_id', $site->id)->where('date', '>=', $since)->toBase()
            ->selectRaw('campaign, source, currency, SUM(cost_cents) AS cents')->groupBy('campaign', 'source', 'currency')->get();
        foreach ($rows as $row) {
            $key = mb_strtolower((string) $row->campaign).'|'.mb_strtolower((string) $row->source);
            $totals[$key] ??= ['campaign' => (string) $row->campaign, 'source' => (string) $row->source, 'amounts' => []];
            $totals[$key]['amounts'][(string) $row->currency] = ($totals[$key]['amounts'][(string) $row->currency] ?? 0.0) + (int) $row->cents / 100;
        }

        return $totals;
    }

    /**
     * Work out a campaign's cost, cost per converting visit and return on ad spend (revenue divided by cost, when
     * both are in one and the same currency).
     *
     * @param  array{campaign: string, source: string, amounts: array<string, float>}|null  $spend
     * @param  int  $converted
     * @param  array<string, float>  $revenue  keyed by currency
     * @return array{cost: string|null, cost_per_conversion: string|null, roas: float|null}
     */
    private function costs(?array $spend, int $converted, array $revenue): array
    {
        if ($spend === null) {
            return ['cost' => null, 'cost_per_conversion' => null, 'roas' => null];
        }
        $single = count($spend['amounts']) === 1 ? array_key_first($spend['amounts']) : null;
        $total = $single === null ? 0.0 : $spend['amounts'][$single];

        return [
            'cost' => Revenue::format($spend['amounts']),
            'cost_per_conversion' => $single !== null && $converted > 0 ? Revenue::format([$single => $total / $converted]) : null,
            'roas' => $single !== null && $total > 0 && array_keys(array_filter($revenue)) === [$single] ? round($revenue[$single] / $total, 2) : null,
        ];
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
