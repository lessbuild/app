<?php

declare(strict_types=1);

namespace App\Queries\Analytics;

use App\Data\Analytics\ReportPeriod;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsSite;
use App\Models\AnalyticsVisit;
use App\Support\Country;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Automatic insights: what changed most between a period and the one before it, across pages, channels, sources,
 * countries and devices, in plain sentences.
 */
final class InsightsQuery
{
    /**
     * The smallest count, now or before, worth an insight; below it changes are noise.
     *
     * @var int
     */
    private const MINIMUM = 10;

    /**
     * Find up to ten notable changes: overall visits when they moved at least 20%, then the biggest movers (at least
     * 50% and ten visits or pageviews either side), newcomers first, then by how much they changed. Periods without a
     * comparison are compared with the period just before.
     *
     * @param  AnalyticsSite  $site
     * @param  ReportPeriod  $period
     * @return list<array{tone: string, dimension: string, label: string, current: int, previous: int, change: string}>
     */
    public function handle(AnalyticsSite $site, ReportPeriod $period): array
    {
        $previousStart = $period->previousStart ?? $period->start->subDays($period->days);
        $previousEnd = $period->previousEnd ?? $period->start->subSecond();
        $windows = [[$period->start->utc(), $period->end->utc()], [$previousStart->utc(), $previousEnd->utc()]];
        $insights = [];

        [$visitsNow, $visitsBefore] = array_map(fn (array $window): int => $this->visits($site, ...$window)->count(), $windows);
        if ($visitsBefore >= self::MINIMUM && abs($visitsNow - $visitsBefore) / $visitsBefore >= 0.2) {
            $insights[] = $this->insight('Visits', __('All visits'), $visitsNow, $visitsBefore);
        }

        $movers = [];
        $dimensions = [
            ['Page', fn (CarbonImmutable $from, CarbonImmutable $until) => $this->events($site, $from, $until)->where('type', 'pageview'), 'path'],
            ['Channel', fn (CarbonImmutable $from, CarbonImmutable $until) => $this->visits($site, $from, $until)->whereNotNull('entry_channel'), 'entry_channel'],
            ['Source', fn (CarbonImmutable $from, CarbonImmutable $until) => $this->visits($site, $from, $until), "COALESCE(NULLIF(entry_utm_source, ''), entry_referrer_host)"],
            ['Country', fn (CarbonImmutable $from, CarbonImmutable $until) => $this->visits($site, $from, $until)->whereNotNull('country_code'), 'country_code'],
            ['Device', fn (CarbonImmutable $from, CarbonImmutable $until) => $this->events($site, $from, $until)->where('type', 'pageview'), 'device_category'],
        ];
        foreach ($dimensions as [$dimension, $query, $column]) {
            [$now, $before] = array_map(fn (array $window): array => $this->counts($query(...$window), $column), $windows);
            foreach (array_unique([...array_keys($now), ...array_keys($before)]) as $label) {
                $current = $now[$label] ?? 0;
                $previous = $before[$label] ?? 0;
                if ($label === '' || max($current, $previous) < self::MINIMUM || ($previous > 0 && abs($current - $previous) / $previous < 0.5)) {
                    continue;
                }
                $movers[] = $this->insight($dimension, $dimension === 'Country' ? Country::label((string) $label) : (string) $label, $current, $previous);
            }
        }
        usort($movers, fn (array $left, array $right): int => [$right['previous'] === 0, abs($right['current'] - $right['previous'])] <=> [$left['previous'] === 0, abs($left['current'] - $left['previous'])]);

        return array_slice([...$insights, ...$movers], 0, 10);
    }

    /**
     * Describe one change.
     *
     * @param  string  $dimension
     * @param  string  $label
     * @param  int  $current
     * @param  int  $previous
     * @return array{tone: string, dimension: string, label: string, current: int, previous: int, change: string}
     */
    private function insight(string $dimension, string $label, int $current, int $previous): array
    {
        return [
            'tone' => $previous === 0 ? 'info' : ($current >= $previous ? 'success' : 'warning'),
            'dimension' => $dimension,
            'label' => $label,
            'current' => $current,
            'previous' => $previous,
            'change' => $previous === 0 ? 'New' : sprintf('%+.0f%%', ($current - $previous) / $previous * 100),
        ];
    }

    /**
     * Count rows per value of a column, the top 100.
     *
     * @param  Builder<AnalyticsEvent>|Builder<AnalyticsVisit>  $query
     * @param  literal-string  $column  a column or SQL expression
     * @return array<string, int>
     */
    private function counts(Builder $query, string $column): array
    {
        return $query->toBase()->selectRaw("{$column} AS insight_label, COUNT(*) AS total")->groupBy('insight_label')
            ->orderByDesc('total')->limit(100)->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->insight_label => (int) $row->total])->all();
    }

    /**
     * Start a query for the site's countable events in a window.
     *
     * @param  AnalyticsSite  $site
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable  $until
     * @return Builder<AnalyticsEvent>
     */
    private function events(AnalyticsSite $site, CarbonImmutable $from, CarbonImmutable $until): Builder
    {
        return AnalyticsEvent::query()->where('site_id', $site->id)->countable()->whereBetween('occurred_at', [$from, $until]);
    }

    /**
     * Start a query for the site's visits last seen in a window.
     *
     * @param  AnalyticsSite  $site
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable  $until
     * @return Builder<AnalyticsVisit>
     */
    private function visits(AnalyticsSite $site, CarbonImmutable $from, CarbonImmutable $until): Builder
    {
        return AnalyticsVisit::query()->where('site_id', $site->id)->whereBetween('last_seen_at', [$from, $until]);
    }
}
