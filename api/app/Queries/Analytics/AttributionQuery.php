<?php

declare(strict_types=1);

namespace App\Queries\Analytics;

use App\Data\Analytics\ReportPeriod;
use App\Models\AnalyticsSite;
use App\Support\Analytics\Revenue;
use Illuminate\Support\Facades\DB;

/**
 * Credit for goals and revenue by channel or campaign: last touch (where the converting visit came from) and first
 * touch (where the visitor first came from, when the snippet recognises returning browsers; otherwise the same visit).
 */
final class AttributionQuery
{
    /**
     * Count conversions and revenue in the period per channel or campaign, both ways, most conversions first.
     *
     * @param  AnalyticsSite  $site
     * @param  ReportPeriod  $period
     * @param  string  $dimension  channel or campaign
     * @return list<array{label: string, first: int, last: int, first_revenue: string|null, last_revenue: string|null}>
     */
    public function handle(AnalyticsSite $site, ReportPeriod $period, string $dimension): array
    {
        $visitColumn = $dimension === 'campaign' ? 'entry_utm_campaign' : 'entry_channel';
        $eventColumn = $dimension === 'campaign' ? 'utm_campaign' : 'channel';
        $conversions = DB::table('analytics_goal_conversions as c')
            ->join('analytics_events as e', 'e.id', '=', 'c.analytics_event_id')
            ->leftJoin('analytics_visits as v', 'v.id', '=', 'c.visit_id')
            ->where('c.site_id', $site->id)->whereBetween('c.converted_at', [$period->start->utc(), $period->end->utc()])
            ->selectRaw("v.{$visitColumn} AS last_touch, e.returning_hash AS returning_hash, ".Revenue::amount('e').' AS amount, '.Revenue::currency('e').' AS currency')
            ->limit(20000)->get();
        $hashes = $conversions->pluck('returning_hash')->filter()->unique()->values()->all();
        $firstTouch = [];
        foreach (array_chunk($hashes, 500) as $chunk) {
            $first = DB::table('analytics_events')->where('site_id', $site->id)->where('type', 'pageview')->whereIn('returning_hash', $chunk)
                ->selectRaw("returning_hash, {$eventColumn} AS touch, ROW_NUMBER() OVER (PARTITION BY returning_hash ORDER BY occurred_at, id) AS position");
            foreach (DB::query()->fromSub($first, 'firsts')->where('position', 1)->get() as $row) {
                $firstTouch[(string) $row->returning_hash] = $row->touch;
            }
        }

        /** @var array<string, array{label: string, first: int, last: int, first_amounts: array<string, float>, last_amounts: array<string, float>}> $rows */
        $rows = [];
        $none = $dimension === 'campaign' ? '(no campaign)' : 'Direct';
        foreach ($conversions as $conversion) {
            $last = $conversion->last_touch === null || $conversion->last_touch === '' ? $none : (string) $conversion->last_touch;
            $first = $last;
            if ($conversion->returning_hash !== null && array_key_exists((string) $conversion->returning_hash, $firstTouch)) {
                $touch = $firstTouch[(string) $conversion->returning_hash];
                $first = $touch === null || $touch === '' ? $none : (string) $touch;
            }
            foreach (['first' => $first, 'last' => $last] as $way => $label) {
                $row = $rows[$label] ?? ['label' => $label, 'first' => 0, 'last' => 0, 'first_amounts' => [], 'last_amounts' => []];
                $row[$way]++;
                if ($conversion->amount !== null) {
                    $currency = (string) $conversion->currency;
                    $row[$way.'_amounts'][$currency] = ($row[$way.'_amounts'][$currency] ?? 0.0) + (float) $conversion->amount;
                }
                $rows[$label] = $row;
            }
        }
        $rows = array_values($rows);
        usort($rows, fn (array $a, array $b): int => [$b['last'] + $b['first'], $a['label']] <=> [$a['last'] + $a['first'], $b['label']]);

        return array_map(fn (array $row): array => [
            'label' => $row['label'], 'first' => $row['first'], 'last' => $row['last'],
            'first_revenue' => Revenue::format($row['first_amounts']), 'last_revenue' => Revenue::format($row['last_amounts']),
        ], array_slice($rows, 0, 25));
    }
}
