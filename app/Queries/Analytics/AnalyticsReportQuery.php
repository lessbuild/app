<?php

declare(strict_types=1);

namespace App\Queries\Analytics;

use App\Models\AnalyticsDailyAggregate;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsGoal;
use App\Models\AnalyticsGoalConversion;
use App\Models\AnalyticsSite;
use App\Models\AnalyticsVisit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class AnalyticsReportQuery
{
    /**
     * Create a new AnalyticsReportQuery instance.
     *
     * @param  LiveVisitorsQuery  $live  Reads the last five minutes.
     */
    public function __construct(private readonly LiveVisitorsQuery $live) {}

    /**
     * Build an analytics site's report for today (`$days` = 1, per hour, compared with yesterday up to the same time)
     * or the last `$days` days (clamped to 7–395) compared with the period before: headline metrics, a pageview series, top pages, entry and exit pages, sources, devices, browsers, systems,
     * campaigns, recent activity and goal counts. Unfiltered reports longer than 90 days read the daily aggregates
     * (raw events may be past retention by then); the rest is counted in the database from events and visits. Only
     * events whose batch has been processed are counted.
     *
     * @param  AnalyticsSite  $site
     * @param  int  $days
     * @param  array<string, string|null>  $filters
     * @return array<string, mixed>
     */
    public function handle(AnalyticsSite $site, int $days = 30, array $filters = []): array
    {
        $days = $days === 1 ? 1 : min(max($days, 7), 395);
        $end = CarbonImmutable::now($site->timezone)->endOfDay();
        $start = $end->subDays($days - 1)->startOfDay();
        $comparisonStart = $start->subDays($days);

        if ($days > 90 && ! array_filter($filters)) {
            $aggregateSummary = $this->fromAggregates($site, $days, $start, $end, $comparisonStart, $filters);

            if ($aggregateSummary !== null) {
                return $aggregateSummary;
            }
        }

        return $this->fromEvents($site, $days, $start, $end, $comparisonStart, $filters);
    }

    /**
     * Build the report from raw events and visits. Every count, ranking and chart is worked out by the database, so
     * memory use doesn't grow with a site's traffic.
     *
     * @param  AnalyticsSite  $site
     * @param  int  $days
     * @param  CarbonImmutable  $start
     * @param  CarbonImmutable  $end
     * @param  CarbonImmutable  $comparisonStart
     * @param  array<string, string|null>  $filters
     * @return array<string, mixed>
     */
    private function fromEvents(AnalyticsSite $site, int $days, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $comparisonStart, array $filters): array
    {
        $startUtc = $start->utc();
        $endUtc = $end->utc();
        // Today is compared with yesterday up to the same time, not all of yesterday.
        $previousEndUtc = $days === 1 ? CarbonImmutable::now()->subDay()->utc() : $startUtc;
        $totals = $this->events($site, $comparisonStart->utc(), $endUtc, $filters)->toBase()
            ->selectRaw("COUNT(CASE WHEN type = 'pageview' AND occurred_at >= ? THEN 1 END) AS pageviews", [$startUtc])
            ->selectRaw("COUNT(CASE WHEN type = 'pageview' AND occurred_at < ? THEN 1 END) AS previous_pageviews", [$previousEndUtc])
            ->selectRaw('COUNT(DISTINCT CASE WHEN occurred_at >= ? THEN visitor_hash END) AS visitors', [$startUtc])
            ->selectRaw('COUNT(DISTINCT CASE WHEN occurred_at < ? THEN visitor_hash END) AS previous_visitors', [$previousEndUtc])
            ->selectRaw('COUNT(CASE WHEN occurred_at >= ? THEN 1 END) AS events', [$startUtc])
            ->first();
        $bounceCutoff = CarbonImmutable::now()->subMinutes(30)->utc();
        $visitTotals = $this->visits($site, $comparisonStart->utc(), $endUtc, $filters)->toBase()
            ->selectRaw('COUNT(CASE WHEN last_seen_at >= ? THEN 1 END) AS visits', [$startUtc])
            ->selectRaw('COUNT(CASE WHEN last_seen_at < ? THEN 1 END) AS previous_visits', [$previousEndUtc])
            ->selectRaw('COUNT(CASE WHEN last_seen_at >= ? AND conversion_count > 0 THEN 1 END) AS converted', [$startUtc])
            ->selectRaw('COUNT(CASE WHEN last_seen_at >= ? AND last_seen_at <= ? AND pageviews > 0 THEN 1 END) AS eligible', [$startUtc, $bounceCutoff])
            ->selectRaw('COUNT(CASE WHEN last_seen_at >= ? AND last_seen_at <= ? AND pageviews = 1 AND conversion_count = 0 THEN 1 END) AS bounces', [$startUtc, $bounceCutoff])
            ->first();
        $number = fn (?object $row, string $column): int => (int) ($row->{$column} ?? 0);
        $goals = AnalyticsGoal::query()->where('site_id', $site->id)->where('active', true)->orderBy('id')->get();
        $conversions = $this->conversions($site, $goals, $startUtc, $endUtc, $filters);

        $currentPageviews = $number($totals, 'pageviews');
        $previousPageviews = $number($totals, 'previous_pageviews');
        $currentVisitors = $this->averageVisitors($number($totals, 'visitors'), $days);
        $previousVisitors = $this->averageVisitors($number($totals, 'previous_visitors'), $days);
        $hasVisits = $number($visitTotals, 'visits') > 0;
        $currentVisitCount = $hasVisits ? $number($visitTotals, 'visits') : $this->estimateVisits($site, $startUtc, $endUtc, $filters);
        $previousVisitCount = $number($visitTotals, 'previous_visits') ?: $this->estimateVisits($site, $comparisonStart->utc(), $previousEndUtc->subSecond(), $filters);
        $convertedVisits = $hasVisits ? $number($visitTotals, 'converted') : $conversions['visitors'];
        $conversionRate = $currentVisitCount > 0 ? round(($convertedVisits / $currentVisitCount) * 100, 1) : 0;
        $eligible = $number($visitTotals, 'eligible');
        $bounceRate = $eligible > 0 ? round(($number($visitTotals, 'bounces') / $eligible) * 100, 1) : null;
        $currentEvents = fn () => $this->events($site, $startUtc, $endUtc, $filters);
        $currentVisits = fn () => $this->visits($site, $startUtc, $endUtc, $filters);

        return [
            'range' => ['days' => $days, 'start' => $start, 'end' => $end],
            'metrics' => [
                ['label' => 'Pageviews', 'value' => number_format($currentPageviews), 'change' => $this->change($currentPageviews, $previousPageviews), 'tone' => 'primary'],
                ['label' => 'Visitors', 'value' => number_format($currentVisitors), 'change' => $this->change($currentVisitors, $previousVisitors), 'tone' => 'info'],
                ['label' => 'Visits', 'value' => number_format($currentVisitCount), 'change' => $this->change($currentVisitCount, $previousVisitCount), 'tone' => 'info'],
                ['label' => 'Conversion rate', 'value' => number_format($conversionRate, 1).'%', 'change' => null, 'tone' => 'success'],
                ['label' => 'Bounce rate', 'value' => $bounceRate === null ? '—' : number_format($bounceRate, 1).'%', 'change' => null, 'tone' => 'warning'],
                ['label' => 'Active goals', 'value' => number_format($goals->count()), 'change' => null, 'tone' => 'warning'],
            ],
            'granularity' => $days === 1 ? 'hour' : 'day',
            'series' => $days === 1 ? $this->hourSeries($site, $start, $filters) : $this->series($site, $start, $end, $filters),
            'pages' => $this->rank($currentEvents()->where('type', 'pageview'), $this->labelOf('path')),
            'entryPages' => $this->rank($currentVisits(), $this->labelOf('landing_path')),
            'exitPages' => $this->rank($currentVisits(), $this->labelOf('exit_path')),
            'sources' => $hasVisits
                ? $this->rank($currentVisits()->where('pageviews', '>', 0), $this->sourceLabel('entry_utm_source', 'entry_utm_medium', 'entry_referrer_host'))
                : $this->rank($currentEvents()->where('type', 'pageview'), $this->sourceLabel('utm_source', 'utm_medium', 'referrer_host')),
            'countries' => $hasVisits
                ? $this->rank($currentVisits(), $this->labelOf('country_code'))
                : $this->rank($currentEvents()->where('type', 'pageview'), $this->labelOf('country_code')),
            'devices' => $this->rank($currentEvents(), $this->labelOf('device_category')),
            'browsers' => $this->rank($currentEvents(), $this->labelOf('browser')),
            'operatingSystems' => $this->rank($currentEvents(), $this->labelOf('operating_system')),
            'campaigns' => $hasVisits
                ? $this->rank($currentVisits()->whereNotNull('entry_utm_campaign'), 'entry_utm_campaign')
                : $this->rank($currentEvents()->whereNotNull('utm_campaign'), 'utm_campaign'),
            'recent' => $this->live->handle($site, $filters),
            'filters' => $filters,
            'goals' => $goals->map(fn (AnalyticsGoal $goal): array => [
                'name' => $goal->name,
                'value' => $conversions['perGoal'][$goal->id] ?? 0,
                'kind' => $goal->kind,
            ])->values()->all(),
            'lastProcessedAt' => $site->last_processed_at,
            'hasData' => $number($totals, 'events') > 0,
        ];
    }

    /**
     * Start a query for the site's countable events between two times, with the report's filters applied.
     *
     * @param  AnalyticsSite  $site
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable  $until
     * @param  array<string, string|null>  $filters
     * @return Builder<AnalyticsEvent>
     */
    private function events(AnalyticsSite $site, CarbonImmutable $from, CarbonImmutable $until, array $filters): Builder
    {
        return AnalyticsEvent::query()
            ->where('site_id', $site->id)
            ->countable()
            ->whereBetween('occurred_at', [$from, $until])
            ->matchingReportFilters($filters);
    }

    /**
     * Start a query for the site's visits last seen between two times, with the report's filters applied. Source and
     * campaign filters match where the visit started; path and device filters keep visits with a matching event.
     *
     * @param  AnalyticsSite  $site
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable  $until
     * @param  array<string, string|null>  $filters
     * @return Builder<AnalyticsVisit>
     */
    private function visits(AnalyticsSite $site, CarbonImmutable $from, CarbonImmutable $until, array $filters): Builder
    {
        $path = $filters['path'] ?? null;
        $device = $filters['device'] ?? null;

        return AnalyticsVisit::query()
            ->where('site_id', $site->id)
            ->whereBetween('last_seen_at', [$from, $until])
            ->when($filters['source'] ?? null, fn (Builder $query, string $source) => $query->where(function (Builder $query) use ($source): void {
                $query->where('entry_utm_source', $source)->orWhere('entry_referrer_host', $source);
            }))
            ->when($filters['campaign'] ?? null, fn (Builder $query, string $campaign) => $query->where('entry_utm_campaign', $campaign))
            ->when($filters['country'] ?? null, fn (Builder $query, string $country) => $query->where('country_code', $country))
            ->when($path !== null || $device !== null, fn (Builder $query) => $query->whereExists(function (QueryBuilder $events) use ($path, $device): void {
                $events->selectRaw('1')->from('analytics_events as visit_events')
                    ->whereColumn('visit_events.site_id', 'analytics_visits.site_id')
                    ->where(function (QueryBuilder $identity): void {
                        $identity->whereColumn('visit_events.session_id', 'analytics_visits.session_id')
                            ->orWhere(fn (QueryBuilder $byVisitor) => $byVisitor->whereNull('analytics_visits.session_id')->whereColumn('visit_events.visitor_hash', 'analytics_visits.visitor_hash'));
                    })
                    ->whereColumn('visit_events.occurred_at', '>=', 'analytics_visits.started_at')
                    ->whereColumn('visit_events.occurred_at', '<=', 'analytics_visits.last_seen_at')
                    ->when($path, fn (QueryBuilder $query, string $path) => $query->where('visit_events.path', $path))
                    ->when($device, fn (QueryBuilder $query, string $device) => $query->where('visit_events.device_category', $device));
            }));
    }

    /**
     * Count goal completions in the period per goal (judged by each goal's definition at the time, as recorded when
     * the events were processed), and how many visitors completed any goal.
     *
     * @param  AnalyticsSite  $site
     * @param  Collection<int, AnalyticsGoal>  $goals
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable  $until
     * @param  array<string, string|null>  $filters
     * @return array{perGoal: array<int, int>, visitors: int}
     */
    private function conversions(AnalyticsSite $site, Collection $goals, CarbonImmutable $from, CarbonImmutable $until, array $filters): array
    {
        if ($goals->isEmpty()) {
            return ['perGoal' => [], 'visitors' => 0];
        }
        $matching = AnalyticsGoalConversion::query()
            ->whereIn('goal_id', $goals->pluck('id'))
            ->whereIn('analytics_event_id', $this->events($site, $from, $until, $filters)->select('id'));
        $perGoal = (clone $matching)->toBase()->selectRaw('goal_id, COUNT(*) AS total')->groupBy('goal_id')->pluck('total', 'goal_id')
            ->mapWithKeys(fn (mixed $total, mixed $goal): array => [(int) $goal => (int) $total])->all();
        $visitors = AnalyticsEvent::query()->whereIn('id', $matching->select('analytics_event_id'))->whereNotNull('visitor_hash')->distinct()->count('visitor_hash');

        return ['perGoal' => $perGoal, 'visitors' => $visitors];
    }

    /**
     * Turn a count of daily visitor hashes into visitors per day. Hashes rotate every day, so counting distinct hashes
     * over the period adds up each day's unique visitors.
     *
     * @param  int  $dailyVisitors
     * @param  int  $days
     * @return int
     */
    private function averageVisitors(int $dailyVisitors, int $days): int
    {
        return $dailyVisitors > 0 ? max(1, (int) round($dailyVisitors / $days)) : 0;
    }

    /**
     * Estimate visits from pageviews when no visits have been built yet: one per session, or per daily visitor hash.
     *
     * @param  AnalyticsSite  $site
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable  $until
     * @param  array<string, string|null>  $filters
     * @return int
     */
    private function estimateVisits(AnalyticsSite $site, CarbonImmutable $from, CarbonImmutable $until, array $filters): int
    {
        return $this->events($site, $from, $until, $filters)->where('type', 'pageview')->toBase()
            ->selectRaw('COUNT(DISTINCT COALESCE(session_id, visitor_hash, CAST(event_id AS TEXT))) AS visits')
            ->value('visits') ?? 0;
    }

    /**
     * Count pageviews per day in the site's timezone, with empty days as zero.
     *
     * @param  AnalyticsSite  $site
     * @param  CarbonImmutable  $start
     * @param  CarbonImmutable  $end
     * @param  array<string, string|null>  $filters
     * @return array<int, array{date: string, value: int}>
     */
    private function series(AnalyticsSite $site, CarbonImmutable $start, CarbonImmutable $end, array $filters): array
    {
        $days = [];
        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $days[$date->toDateString()] = 0;
        }
        foreach ($this->hourlyPageviews($site, $start, $end, $filters) as $hour => $count) {
            $day = substr($hour, 0, 10);
            if (array_key_exists($day, $days)) {
                $days[$day] += $count;
            }
        }

        return collect($days)->map(fn (int $value, string $date): array => ['date' => CarbonImmutable::parse($date)->format('M j'), 'value' => $value])->values()->all();
    }

    /**
     * Count pageviews in each hour of one local day, with empty hours as zero.
     *
     * @param  AnalyticsSite  $site
     * @param  CarbonImmutable  $day  the start of the local day
     * @param  array<string, string|null>  $filters
     * @return array<int, array{date: string, value: int}>
     */
    private function hourSeries(AnalyticsSite $site, CarbonImmutable $day, array $filters): array
    {
        $counts = $this->hourlyPageviews($site, $day, $day->endOfDay(), $filters);
        $series = [];
        for ($hour = $day; $hour->isSameDay($day); $hour = $hour->addHour()) {
            $series[] = ['date' => $hour->format('H:00'), 'value' => $counts[$hour->format('Y-m-d H')] ?? 0];
        }

        return $series;
    }

    /**
     * Count pageviews per hour of the site's local time ("Y-m-d H" keys). The database groups by UTC hour, shifted by
     * the part of the site's offset that isn't whole hours (such as India's 30 minutes) so each group is one local hour.
     *
     * @param  AnalyticsSite  $site
     * @param  CarbonImmutable  $start
     * @param  CarbonImmutable  $end
     * @param  array<string, string|null>  $filters
     * @return array<string, int>
     */
    private function hourlyPageviews(AnalyticsSite $site, CarbonImmutable $start, CarbonImmutable $end, array $filters): array
    {
        $shift = ((CarbonImmutable::now($site->timezone)->utcOffset() % 60) + 60) % 60;
        [$expression, $binding] = DB::getDriverName() === 'pgsql'
            ? ["to_char(date_trunc('hour', occurred_at + CAST(? AS INTEGER) * INTERVAL '1 minute'), 'YYYY-MM-DD HH24')", $shift]
            : ["strftime('%Y-%m-%d %H', occurred_at, ?)", "+{$shift} minutes"];
        $hours = [];
        $rows = $this->events($site, $start->utc(), $end->utc(), $filters)->where('type', 'pageview')->toBase()
            ->selectRaw("{$expression} AS hour_bucket, COUNT(*) AS total", [$binding])
            ->groupBy('hour_bucket')
            ->get();
        foreach ($rows as $row) {
            $local = CarbonImmutable::createFromFormat('Y-m-d H', (string) $row->hour_bucket, 'UTC')?->startOfHour()->subMinutes($shift)->setTimezone($site->timezone);
            if ($local !== null) {
                $key = $local->format('Y-m-d H');
                $hours[$key] = ($hours[$key] ?? 0) + (int) $row->total;
            }
        }

        return $hours;
    }

    /**
     * Rank the five most common values of a column or SQL expression.
     *
     * @param  Builder<AnalyticsEvent>|Builder<AnalyticsVisit>  $query
     * @param  literal-string  $label  a column name or a SQL expression built from literals
     * @return array<int, array{label: string, value: int}>
     */
    private function rank(Builder $query, string $label): array
    {
        return $query->toBase()
            ->selectRaw("{$label} AS ranked_label, COUNT(*) AS total")
            ->groupBy('ranked_label')
            ->orderByDesc('total')
            ->orderBy('ranked_label')
            ->limit(5)
            ->get()
            ->map(fn (object $row): array => ['label' => (string) $row->ranked_label, 'value' => (int) $row->total])
            ->all();
    }

    /**
     * Get a SQL expression for a column's value, with missing values as "Unknown".
     *
     * @param  literal-string  $column
     * @return literal-string
     */
    private function labelOf(string $column): string
    {
        return "COALESCE(NULLIF({$column}, ''), 'Unknown')";
    }

    /**
     * Get a SQL expression labelling where traffic came from: the campaign source (and medium) when tagged, else the
     * referring site, else "Direct / unknown".
     *
     * @param  literal-string  $source
     * @param  literal-string  $medium
     * @param  literal-string  $referrer
     * @return literal-string
     */
    private function sourceLabel(string $source, string $medium, string $referrer): string
    {
        return "CASE WHEN {$source} IS NOT NULL AND {$source} <> '' THEN {$source} || CASE WHEN {$medium} IS NOT NULL AND {$medium} <> '' THEN ' / ' || {$medium} ELSE '' END ELSE COALESCE(NULLIF({$referrer}, ''), 'Direct / unknown') END";
    }

    /**
     * Calculate the change from the previous period as a signed percentage, "New" when there was nothing before, or
     * null when there's nothing either time.
     *
     * @param  int  $current
     * @param  int  $previous
     * @return string|null
     */
    private function change(int $current, int $previous): ?string
    {
        if ($previous === 0) {
            return $current > 0 ? 'New' : null;
        }

        return sprintf('%+.1f%%', (($current - $previous) / $previous) * 100);
    }

    /**
     * Build the report from daily aggregates, or return null when none exist yet. Entry and exit pages, recent
     * activity and goals aren't aggregated, so they're left empty.
     *
     * @param  AnalyticsSite  $site
     * @param  int  $days
     * @param  CarbonImmutable  $start
     * @param  CarbonImmutable  $end
     * @param  CarbonImmutable  $comparisonStart
     * @param  array<string, string|null>  $filters
     * @return array<string, mixed>|null
     */
    private function fromAggregates(AnalyticsSite $site, int $days, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $comparisonStart, array $filters): ?array
    {
        $rows = AnalyticsDailyAggregate::query()
            ->where('site_id', $site->id)
            ->where('dimension', 'all')
            ->whereBetween('local_date', [$comparisonStart->toDateString(), $end->toDateString()])
            ->orderBy('local_date')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $current = $rows->filter(fn (AnalyticsDailyAggregate $row): bool => CarbonImmutable::parse($row->local_date)->betweenIncluded($start->toDateString(), $end->toDateString()));
        $previous = $rows->filter(fn (AnalyticsDailyAggregate $row): bool => CarbonImmutable::parse($row->local_date)->betweenIncluded($comparisonStart->toDateString(), $start->subDay()->toDateString()));
        $currentPageviews = $this->aggregateSum($current, 'pageviews');
        $previousPageviews = $this->aggregateSum($previous, 'pageviews');
        $currentVisitors = $this->aggregateAverageVisitors($current, $days);
        $previousVisitors = $this->aggregateAverageVisitors($previous, $days);
        $currentVisits = $this->aggregateSum($current, 'visits');
        $previousVisits = $this->aggregateSum($previous, 'visits');
        $convertedVisits = $this->aggregateSum($current, 'converted_visits');
        $conversionRate = $currentVisits > 0 ? round(($convertedVisits / $currentVisits) * 100, 1) : 0;
        $eligible = $this->aggregateSum($current, 'bounce_eligible');
        $bounces = $this->aggregateSum($current, 'bounces');
        $bounceRate = $eligible > 0 ? round(($bounces / $eligible) * 100, 1) : null;
        $goals = AnalyticsGoal::query()->where('site_id', $site->id)->where('active', true)->get();

        return [
            'range' => ['days' => $days, 'start' => $start, 'end' => $end],
            'metrics' => [
                ['label' => 'Pageviews', 'value' => number_format($currentPageviews), 'change' => $this->change($currentPageviews, $previousPageviews), 'tone' => 'primary'],
                ['label' => 'Visitors', 'value' => number_format($currentVisitors), 'change' => $this->change($currentVisitors, $previousVisitors), 'tone' => 'info'],
                ['label' => 'Visits', 'value' => number_format($currentVisits), 'change' => $this->change($currentVisits, $previousVisits), 'tone' => 'info'],
                ['label' => 'Conversion rate', 'value' => number_format($conversionRate, 1).'%', 'change' => null, 'tone' => 'success'],
                ['label' => 'Bounce rate', 'value' => $bounceRate === null ? '—' : number_format($bounceRate, 1).'%', 'change' => null, 'tone' => 'warning'],
                ['label' => 'Active goals', 'value' => number_format($goals->count()), 'change' => null, 'tone' => 'warning'],
            ],
            'granularity' => 'day',
            'series' => $this->aggregateSeries($current, $start, $end),
            'pages' => $this->aggregateRanking($site, 'path', $start, $end, 'pageviews'),
            'entryPages' => [],
            'exitPages' => [],
            'sources' => $this->aggregateRanking($site, 'source', $start, $end, 'visits'),
            'countries' => $this->aggregateRanking($site, 'country', $start, $end, 'visits'),
            'devices' => $this->aggregateRanking($site, 'device', $start, $end, 'pageviews'),
            'browsers' => $this->aggregateRanking($site, 'browser', $start, $end, 'pageviews'),
            'operatingSystems' => $this->aggregateRanking($site, 'operating_system', $start, $end, 'pageviews'),
            'campaigns' => $this->aggregateRanking($site, 'campaign', $start, $end, 'visits'),
            'recent' => ['visitorCount' => 0, 'events' => []],
            'filters' => $filters,
            'goals' => [],
            'lastProcessedAt' => $site->last_processed_at,
            'hasData' => $current->isNotEmpty(),
        ];
    }

    /**
     * Total one column across aggregate rows.
     *
     * @param  Collection<int, AnalyticsDailyAggregate>  $rows
     * @param  string  $column
     * @return int
     */
    private function aggregateSum(Collection $rows, string $column): int
    {
        return (int) $rows->sum(fn (AnalyticsDailyAggregate $row): int => (int) $row->{$column});
    }

    /**
     * Average daily visitors from aggregates over the period.
     *
     * @param  Collection<int, AnalyticsDailyAggregate>  $rows
     * @param  int  $days
     * @return int
     */
    private function aggregateAverageVisitors(Collection $rows, int $days): int
    {
        return $rows->isEmpty() ? 0 : max(1, (int) round($this->aggregateSum($rows, 'visitors') / $days));
    }

    /**
     * Count pageviews per day from aggregates, with missing days as zero.
     *
     * @param  Collection<int, AnalyticsDailyAggregate>  $rows
     * @param  CarbonImmutable  $start
     * @param  CarbonImmutable  $end
     * @return list<array{date: string, value: int}>
     */
    private function aggregateSeries(Collection $rows, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $values = $rows->keyBy(fn (AnalyticsDailyAggregate $row): string => CarbonImmutable::parse($row->local_date)->toDateString());
        $series = [];

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $key = $date->toDateString();
            $series[] = ['date' => $date->format('M j'), 'value' => (int) ($values->get($key)->pageviews ?? 0)];
        }

        return $series;
    }

    /**
     * Rank the five largest values of one aggregated dimension over the period.
     *
     * @param  AnalyticsSite  $site
     * @param  string  $dimension
     * @param  CarbonImmutable  $start
     * @param  CarbonImmutable  $end
     * @param  literal-string  $column
     * @return array<int, array{label: string, value: int}>
     */
    private function aggregateRanking(AnalyticsSite $site, string $dimension, CarbonImmutable $start, CarbonImmutable $end, string $column): array
    {
        return AnalyticsDailyAggregate::query()
            ->where('site_id', $site->id)
            ->where('dimension', $dimension)
            ->whereBetween('local_date', [$start->toDateString(), $end->toDateString()])
            ->toBase()
            ->selectRaw("COALESCE(NULLIF(dimension_value, ''), 'Unknown') AS ranked_label, SUM({$column}) AS total")
            ->groupBy('ranked_label')
            ->orderByDesc('total')
            ->orderBy('ranked_label')
            ->limit(5)
            ->get()
            ->map(fn (object $row): array => ['label' => (string) $row->ranked_label, 'value' => (int) $row->total])
            ->all();
    }
}
