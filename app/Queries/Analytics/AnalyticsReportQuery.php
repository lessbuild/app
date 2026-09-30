<?php

declare(strict_types=1);

namespace App\Queries\Analytics;

use App\Data\Analytics\ReportPeriod;
use App\Models\AnalyticsDailyAggregate;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsGoal;
use App\Models\AnalyticsGoalConversion;
use App\Models\AnalyticsSite;
use App\Models\AnalyticsVisit;
use App\Support\Analytics\Revenue;
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
     * @param  PageSpeedQuery  $pageSpeed  Summarises real visitors' page speed.
     * @param  SearchTermsQuery  $searchTerms  Reads Google Search Console, for connected sites.
     */
    public function __construct(private readonly LiveVisitorsQuery $live, private readonly PageSpeedQuery $pageSpeed, private readonly SearchTermsQuery $searchTerms) {}

    /**
     * Build an analytics site's report for a period (a number of days ending today, or a ReportPeriod with custom dates
     * and a comparison): headline metrics against the comparison period, a pageview series (per hour for a single day),
     * top pages, entry and exit pages, sources, devices, browsers, systems, campaigns, recent activity and goal
     * counts. Unfiltered reports longer than 90 days read the daily aggregates (raw events may be past retention by
     * then); the rest is counted in the database from events and visits. Only events whose batch has been processed
     * are counted.
     *
     * @param  AnalyticsSite  $site
     * @param  int|ReportPeriod  $period
     * @param  array<string, string|null>  $filters
     * @return array<string, mixed>
     */
    public function handle(AnalyticsSite $site, int|ReportPeriod $period = 30, array $filters = []): array
    {
        $period = is_int($period) ? ReportPeriod::lastDays($site->timezone, $period) : $period;

        if ($period->days > 90 && ! array_filter($filters)) {
            $aggregateSummary = $this->fromAggregates($site, $period, $filters);

            if ($aggregateSummary !== null) {
                return $aggregateSummary;
            }
        }

        return $this->fromEvents($site, $period, $filters);
    }

    /**
     * Build the report from raw events and visits. Every count, ranking and chart is worked out by the database, so
     * memory use doesn't grow with a site's traffic.
     *
     * @param  AnalyticsSite  $site
     * @param  ReportPeriod  $period
     * @param  array<string, string|null>  $filters
     * @return array<string, mixed>
     */
    private function fromEvents(AnalyticsSite $site, ReportPeriod $period, array $filters): array
    {
        $days = $period->days;
        $startUtc = $period->start->utc();
        $endUtc = $period->end->utc();
        $totals = $this->totals($site, $startUtc, $endUtc, $filters);
        $previous = $period->previousStart !== null && $period->previousEnd !== null
            ? $this->totals($site, $period->previousStart->utc(), $period->previousEnd->utc(), $filters) : null;
        $goals = AnalyticsGoal::query()->where('site_id', $site->id)->where('active', true)->orderBy('id')->get();
        $conversions = $this->conversions($site, $goals, $startUtc, $endUtc, $filters);

        $currentVisitors = $this->averageVisitors($totals['visitors'], $days);
        $previousVisitors = $previous === null ? null : $this->averageVisitors($previous['visitors'], $days);
        $hasVisits = $totals['visits'] > 0;
        $currentVisitCount = $hasVisits ? $totals['visits'] : $this->estimateVisits($site, $startUtc, $endUtc, $filters);
        $previousVisitCount = null;
        if ($previous !== null) {
            $previousVisitCount = $previous['visits'] ?: $this->estimateVisits($site, $period->previousStart->utc(), $period->previousEnd->utc(), $filters);
        }
        $convertedVisits = $hasVisits ? $totals['converted'] : $conversions['visitors'];
        $conversionRate = $currentVisitCount > 0 ? round(($convertedVisits / $currentVisitCount) * 100, 1) : 0;
        $bounceRate = $totals['eligible'] > 0 ? round(($totals['bounces'] / $totals['eligible']) * 100, 1) : null;
        $currentEvents = fn () => $this->events($site, $startUtc, $endUtc, $filters);
        $currentVisits = fn () => $this->visits($site, $startUtc, $endUtc, $filters);

        return [
            'range' => ['days' => $days, 'start' => $period->start, 'end' => $period->end],
            'period' => $period,
            'metrics' => [
                ['label' => 'Pageviews', 'value' => number_format($totals['pageviews']), 'raw' => $totals['pageviews'], 'change' => $this->change($totals['pageviews'], $previous['pageviews'] ?? null), 'tone' => 'primary'],
                ['label' => 'Visitors', 'value' => number_format($currentVisitors), 'raw' => $currentVisitors, 'change' => $this->change($currentVisitors, $previousVisitors), 'tone' => 'info'],
                ['label' => 'Visits', 'value' => number_format($currentVisitCount), 'raw' => $currentVisitCount, 'change' => $this->change($currentVisitCount, $previousVisitCount), 'tone' => 'info'],
                ['label' => 'Conversion rate', 'value' => number_format($conversionRate, 1).'%', 'raw' => (float) $conversionRate, 'change' => null, 'tone' => 'success'],
                ['label' => 'Bounce rate', 'value' => $bounceRate === null ? '—' : number_format($bounceRate, 1).'%', 'raw' => $bounceRate, 'change' => null, 'tone' => 'warning'],
                $this->durationMetric($totals['duration'], $totals['visits'], $previous['duration'] ?? null, $previous['visits'] ?? null),
            ],
            'granularity' => $period->hourly() ? 'hour' : 'day',
            'series' => $period->hourly() ? $this->hourSeries($site, $period->start, $filters) : $this->series($site, $period->start, $period->end, $filters),
            'pages' => $this->rank($currentEvents()->where('type', 'pageview'), $this->labelOf('path')),
            'entryPages' => $this->rank($currentVisits(), $this->labelOf('landing_path')),
            'exitPages' => $this->rank($currentVisits(), $this->labelOf('exit_path')),
            'sources' => $hasVisits
                ? $this->rank($currentVisits()->where('pageviews', '>', 0), $this->sourceLabel('entry_utm_source', 'entry_utm_medium', 'entry_referrer_host'))
                : $this->rank($currentEvents()->where('type', 'pageview'), $this->sourceLabel('utm_source', 'utm_medium', 'referrer_host')),
            'countries' => $hasVisits
                ? $this->rank($currentVisits(), $this->labelOf('country_code'))
                : $this->rank($currentEvents()->where('type', 'pageview'), $this->labelOf('country_code')),
            'devices' => $this->rank($currentEvents()->where('type', 'pageview'), $this->labelOf('device_category')),
            'browsers' => $this->rank($currentEvents()->where('type', 'pageview'), $this->labelOf('browser')),
            'operatingSystems' => $this->rank($currentEvents()->where('type', 'pageview'), $this->labelOf('operating_system')),
            'campaigns' => $hasVisits
                ? $this->rank($currentVisits()->whereNotNull('entry_utm_campaign'), 'entry_utm_campaign')
                : $this->rank($currentEvents()->whereNotNull('utm_campaign'), 'utm_campaign'),
            'outboundLinks' => $this->rank($this->automaticEvents($currentEvents(), 'outbound_link'), $this->labelOf($this->property('url'))),
            'fileDownloads' => $this->rank($this->automaticEvents($currentEvents(), 'file_download'), $this->labelOf($this->property('file'))),
            'notFound' => $this->rank($this->automaticEvents($currentEvents(), 'not_found'), $this->labelOf('path')),
            'vitals' => $this->pageSpeed->handle($site, $startUtc, $endUtc, $filters),
            'searchTerms' => $this->searchTerms->handle($site, $period->start, $period->end, $filters['path'] ?? null),
            'recent' => $this->live->handle($site, $filters),
            'filters' => $filters,
            'goals' => $goals->map(fn (AnalyticsGoal $goal): array => [
                'name' => $goal->name,
                'value' => $conversions['perGoal'][$goal->id] ?? 0,
                'kind' => $goal->kind,
                'revenue' => Revenue::format($conversions['revenue'][$goal->id] ?? []),
            ])->values()->all(),
            'lastProcessedAt' => $site->last_processed_at,
            'hasData' => $totals['events'] > 0,
        ];
    }

    /**
     * Count a stretch of time's pageviews, daily visitors and events, and its visits, conversions, bounces and time
     * spent, with the report's filters applied. Visits still open in the last 30 minutes don't count towards bounces.
     *
     * @param  AnalyticsSite  $site
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable  $until
     * @param  array<string, string|null>  $filters
     * @return array{pageviews: int, visitors: int, events: int, visits: int, converted: int, eligible: int, bounces: int, duration: int}
     */
    private function totals(AnalyticsSite $site, CarbonImmutable $from, CarbonImmutable $until, array $filters): array
    {
        $events = $this->events($site, $from, $until, $filters)->toBase()
            ->selectRaw("COUNT(CASE WHEN type = 'pageview' THEN 1 END) AS pageviews")
            ->selectRaw('COUNT(DISTINCT visitor_hash) AS visitors')
            ->selectRaw('COUNT(*) AS events')
            ->first();
        $bounceCutoff = CarbonImmutable::now()->subMinutes(30)->utc();
        $visits = $this->visits($site, $from, $until, $filters)->toBase()
            ->selectRaw('COUNT(*) AS visits')
            ->selectRaw('COUNT(CASE WHEN conversion_count > 0 THEN 1 END) AS converted')
            ->selectRaw('COUNT(CASE WHEN last_seen_at <= ? AND pageviews > 0 THEN 1 END) AS eligible', [$bounceCutoff])
            ->selectRaw('COUNT(CASE WHEN last_seen_at <= ? AND pageviews = 1 AND conversion_count = 0 THEN 1 END) AS bounces', [$bounceCutoff])
            ->selectRaw('SUM('.$this->visitSeconds().') AS duration')
            ->first();
        $number = fn (?object $row, string $column): int => (int) ($row->{$column} ?? 0);

        return [
            'pageviews' => $number($events, 'pageviews'), 'visitors' => $number($events, 'visitors'), 'events' => $number($events, 'events'),
            'visits' => $number($visits, 'visits'), 'converted' => $number($visits, 'converted'), 'eligible' => $number($visits, 'eligible'),
            'bounces' => $number($visits, 'bounces'), 'duration' => $number($visits, 'duration'),
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
     * campaign filters match where the visit started; path, device, browser and system filters keep visits with a matching event.
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
        $browser = $filters['browser'] ?? null;
        $os = $filters['os'] ?? null;

        return AnalyticsVisit::query()
            ->where('site_id', $site->id)
            ->whereBetween('last_seen_at', [$from, $until])
            ->when($filters['source'] ?? null, fn (Builder $query, string $source) => $query->where(function (Builder $query) use ($source): void {
                $query->where('entry_utm_source', $source)->orWhere('entry_referrer_host', $source);
            }))
            ->when($filters['campaign'] ?? null, fn (Builder $query, string $campaign) => $query->where('entry_utm_campaign', $campaign))
            ->when($filters['country'] ?? null, fn (Builder $query, string $country) => $query->where('country_code', $country))
            ->when($path !== null || $device !== null || $browser !== null || $os !== null, fn (Builder $query) => $query->whereExists(function (QueryBuilder $events) use ($path, $device, $browser, $os): void {
                $events->selectRaw('1')->from('analytics_events as visit_events')
                    ->whereColumn('visit_events.site_id', 'analytics_visits.site_id')
                    ->where(function (QueryBuilder $identity): void {
                        $identity->whereColumn('visit_events.session_id', 'analytics_visits.session_id')
                            ->orWhere(fn (QueryBuilder $byVisitor) => $byVisitor->whereNull('analytics_visits.session_id')->whereColumn('visit_events.visitor_hash', 'analytics_visits.visitor_hash'));
                    })
                    ->whereColumn('visit_events.occurred_at', '>=', 'analytics_visits.started_at')
                    ->whereColumn('visit_events.occurred_at', '<=', 'analytics_visits.last_seen_at')
                    ->when($path, fn (QueryBuilder $query, string $path) => $query->where('visit_events.path', $path))
                    ->when($device, fn (QueryBuilder $query, string $device) => $query->where('visit_events.device_category', $device))
                    ->when($browser, fn (QueryBuilder $query, string $browser) => $query->where('visit_events.browser', $browser))
                    ->when($os, fn (QueryBuilder $query, string $os) => $query->where('visit_events.operating_system', $os));
            }));
    }

    /**
     * Count goal completions in the period per goal (judged by each goal's definition at the time, as recorded when
     * the events were processed), the revenue they carried per goal and currency, and how many visitors completed any
     * goal.
     *
     * @param  AnalyticsSite  $site
     * @param  Collection<int, AnalyticsGoal>  $goals
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable  $until
     * @param  array<string, string|null>  $filters
     * @return array{perGoal: array<int, int>, revenue: array<int, array<string, float>>, visitors: int}
     */
    private function conversions(AnalyticsSite $site, Collection $goals, CarbonImmutable $from, CarbonImmutable $until, array $filters): array
    {
        if ($goals->isEmpty()) {
            return ['perGoal' => [], 'revenue' => [], 'visitors' => 0];
        }
        $matching = AnalyticsGoalConversion::query()
            ->whereIn('goal_id', $goals->pluck('id'))
            ->whereIn('analytics_event_id', $this->events($site, $from, $until, $filters)->select('id'));
        $perGoal = (clone $matching)->toBase()->selectRaw('goal_id, COUNT(*) AS total')->groupBy('goal_id')->pluck('total', 'goal_id')
            ->mapWithKeys(fn (mixed $total, mixed $goal): array => [(int) $goal => (int) $total])->all();
        $revenue = [];
        $rows = (clone $matching)->toBase()
            ->join('analytics_events as revenue_events', 'revenue_events.id', '=', 'analytics_goal_conversions.analytics_event_id')
            ->whereRaw(Revenue::amount('revenue_events').' IS NOT NULL')
            ->selectRaw('analytics_goal_conversions.goal_id AS goal_id, '.Revenue::currency('revenue_events').' AS currency, SUM('.Revenue::amount('revenue_events').') AS amount')
            ->groupBy('analytics_goal_conversions.goal_id', 'currency')
            ->get();
        foreach ($rows as $row) {
            $revenue[(int) $row->goal_id][(string) $row->currency] = (float) $row->amount;
        }
        $visitors = AnalyticsEvent::query()->whereIn('id', $matching->select('analytics_event_id'))->whereNotNull('visitor_hash')->distinct()->count('visitor_hash');

        return ['perGoal' => $perGoal, 'revenue' => $revenue, 'visitors' => $visitors];
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
     * Limit events to one of the tracker's automatic custom events (outbound links, downloads, missing pages).
     *
     * @param  Builder<AnalyticsEvent>  $events
     * @param  string  $name
     * @return Builder<AnalyticsEvent>
     */
    private function automaticEvents(Builder $events, string $name): Builder
    {
        return $events->where('type', 'event')->whereRaw($this->property('name').' = ?', [$name]);
    }

    /**
     * Get a SQL expression reading one text property of an event's properties.
     *
     * @param  'name'|'url'|'file'  $key
     * @return literal-string
     */
    private function property(string $key): string
    {
        return match ($key) {
            'name' => DB::getDriverName() === 'pgsql' ? "properties->>'name'" : "json_extract(properties, '$.name')",
            'url' => DB::getDriverName() === 'pgsql' ? "properties->>'url'" : "json_extract(properties, '$.url')",
            'file' => DB::getDriverName() === 'pgsql' ? "properties->>'file'" : "json_extract(properties, '$.file')",
        };
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
     * Build the average visit duration metric, compared with the period before. Single-page visits count as zero.
     *
     * @param  int  $seconds  total length of the period's visits
     * @param  int  $visits
     * @param  int|null  $previousSeconds  null when not comparing
     * @param  int|null  $previousVisits
     * @return array{label: string, value: string, raw: int|null, change: string|null, tone: string}
     */
    private function durationMetric(int $seconds, int $visits, ?int $previousSeconds, ?int $previousVisits): array
    {
        $average = $visits > 0 ? (int) round($seconds / $visits) : 0;
        $previous = $previousVisits === null ? null : ($previousVisits > 0 ? (int) round((int) $previousSeconds / $previousVisits) : 0);

        return ['label' => 'Visit duration', 'value' => $visits > 0 ? $this->duration($average) : '—', 'raw' => $visits > 0 ? $average : null, 'change' => $visits > 0 ? $this->change($average, $previous) : null, 'tone' => 'warning'];
    }

    /**
     * Write a number of seconds briefly: "45s", "2m 05s" or "1h 02m".
     *
     * @param  int  $seconds
     * @return string
     */
    private function duration(int $seconds): string
    {
        return match (true) {
            $seconds < 60 => $seconds.'s',
            $seconds < 3600 => intdiv($seconds, 60).'m '.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT).'s',
            default => intdiv($seconds, 3600).'h '.str_pad((string) intdiv($seconds % 3600, 60), 2, '0', STR_PAD_LEFT).'m',
        };
    }

    /**
     * Get a SQL expression for a visit's length in whole seconds.
     *
     * @return literal-string
     */
    private function visitSeconds(): string
    {
        return DB::getDriverName() === 'pgsql'
            ? 'CAST(EXTRACT(EPOCH FROM (last_seen_at - started_at)) AS BIGINT)'
            : 'CAST(ROUND((julianday(last_seen_at) - julianday(started_at)) * 86400) AS INTEGER)';
    }

    /**
     * Calculate the change from the previous period as a signed percentage, "New" when there was nothing before, or
     * null when there's nothing either time.
     *
     * @param  int  $current
     * @param  int|null  $previous  null when not comparing
     * @return string|null
     */
    private function change(int $current, ?int $previous): ?string
    {
        if ($previous === null) {
            return null;
        }
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
     * @param  ReportPeriod  $period
     * @param  array<string, string|null>  $filters
     * @return array<string, mixed>|null
     */
    private function fromAggregates(AnalyticsSite $site, ReportPeriod $period, array $filters): ?array
    {
        $days = $period->days;
        $start = $period->start;
        $end = $period->end;
        $daysBetween = fn (CarbonImmutable $from, CarbonImmutable $until) => AnalyticsDailyAggregate::query()
            ->where('site_id', $site->id)
            ->where('dimension', 'all')
            ->whereBetween('local_date', [$from->toDateString(), $until->toDateString()])
            ->orderBy('local_date')
            ->get();
        $current = $daysBetween($start, $end);

        if ($current->isEmpty()) {
            return null;
        }

        $previous = $period->previousStart !== null && $period->previousEnd !== null ? $daysBetween($period->previousStart, $period->previousEnd) : null;
        $currentPageviews = $this->aggregateSum($current, 'pageviews');
        $previousPageviews = $previous === null ? null : $this->aggregateSum($previous, 'pageviews');
        $currentVisitors = $this->aggregateAverageVisitors($current, $days);
        $previousVisitors = $previous === null ? null : $this->aggregateAverageVisitors($previous, $days);
        $currentVisits = $this->aggregateSum($current, 'visits');
        $previousVisits = $previous === null ? null : $this->aggregateSum($previous, 'visits');
        $convertedVisits = $this->aggregateSum($current, 'converted_visits');
        $conversionRate = $currentVisits > 0 ? round(($convertedVisits / $currentVisits) * 100, 1) : 0;
        $eligible = $this->aggregateSum($current, 'bounce_eligible');
        $bounces = $this->aggregateSum($current, 'bounces');
        $bounceRate = $eligible > 0 ? round(($bounces / $eligible) * 100, 1) : null;

        return [
            'range' => ['days' => $days, 'start' => $start, 'end' => $end],
            'period' => $period,
            'metrics' => [
                ['label' => 'Pageviews', 'value' => number_format($currentPageviews), 'raw' => $currentPageviews, 'change' => $this->change($currentPageviews, $previousPageviews), 'tone' => 'primary'],
                ['label' => 'Visitors', 'value' => number_format($currentVisitors), 'raw' => $currentVisitors, 'change' => $this->change($currentVisitors, $previousVisitors), 'tone' => 'info'],
                ['label' => 'Visits', 'value' => number_format($currentVisits), 'raw' => $currentVisits, 'change' => $this->change($currentVisits, $previousVisits), 'tone' => 'info'],
                ['label' => 'Conversion rate', 'value' => number_format($conversionRate, 1).'%', 'raw' => (float) $conversionRate, 'change' => null, 'tone' => 'success'],
                ['label' => 'Bounce rate', 'value' => $bounceRate === null ? '—' : number_format($bounceRate, 1).'%', 'raw' => $bounceRate, 'change' => null, 'tone' => 'warning'],
                $this->durationMetric($this->aggregateSum($current, 'duration_seconds'), $currentVisits, $previous === null ? null : $this->aggregateSum($previous, 'duration_seconds'), $previousVisits),
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
            'outboundLinks' => [],
            'fileDownloads' => [],
            'notFound' => [],
            'vitals' => $this->pageSpeed->handle($site, $start->utc(), $end->utc()),
            'searchTerms' => $this->searchTerms->handle($site, $start, $end),
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
