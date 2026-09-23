<?php

namespace App\Modules\Analytics\Queries\Reporting;

use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\Goal;
use App\Modules\Analytics\Models\ReportDailyAggregate;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class OverviewReport
{
    /**
     * @param  array<string, string|null>  $filters
     * @return array<string, mixed>
     */
    public function for(Site $site, int $days = 30, array $filters = []): array
    {
        $days = min(max($days, 7), 395);
        $end = CarbonImmutable::now($site->timezone)->endOfDay();
        $start = $end->subDays($days - 1)->startOfDay();
        $comparisonStart = $start->subDays($days);

        if ($days > 90 && ! array_filter($filters)) {
            $aggregateSummary = $this->fromAggregates($site, $days, $start, $end, $comparisonStart, $filters);

            if ($aggregateSummary !== null) {
                return $aggregateSummary;
            }
        }

        $events = AnalyticsEvent::query()
            ->where('site_id', $site->id)
            ->where(function ($query): void {
                $query->whereNull('ingestion_batch_id')
                    ->orWhereHas('ingestionBatch', fn ($batchQuery) => $batchQuery->where('status', 'processed'));
            })
            ->whereBetween('occurred_at', [$comparisonStart->utc(), $end->utc()])
            ->when($filters['path'] ?? null, fn ($query, string $path) => $query->where('path', $path))
            ->when($filters['source'] ?? null, fn ($query, string $source) => $query->where(function ($query) use ($source): void {
                $query->where('utm_source', $source)->orWhere('referrer_host', $source);
            }))
            ->when($filters['campaign'] ?? null, fn ($query, string $campaign) => $query->where('utm_campaign', $campaign))
            ->when($filters['device'] ?? null, fn ($query, string $device) => $query->where('device_category', $device))
            ->orderBy('occurred_at')
            ->get();

        $current = $events->filter(fn (AnalyticsEvent $event): bool => $event->occurred_at->betweenIncluded($start->utc(), $end->utc()));
        $previous = $events->filter(fn (AnalyticsEvent $event): bool => $event->occurred_at->betweenIncluded($comparisonStart->utc(), $start->subSecond()->utc()));

        $pageviews = fn (Collection $items): int => $items->where('type', 'pageview')->count();
        $visitors = fn (Collection $items): int => $this->averageDailyVisitors($items, $site->timezone, $days);
        $goals = Goal::query()->where('site_id', $site->id)->where('active', true)->with('versions')->get();

        $currentPageviews = $pageviews($current);
        $previousPageviews = $pageviews($previous);
        $currentVisitors = $visitors($current);
        $previousVisitors = $visitors($previous);
        $visitRows = Visit::query()
            ->where('site_id', $site->id)
            ->whereBetween('last_seen_at', [$comparisonStart->utc(), $end->utc()])
            ->get();
        $currentVisits = $visitRows->filter(fn (Visit $visit): bool => CarbonImmutable::parse($visit->last_seen_at)->betweenIncluded($start->utc(), $end->utc()));
        $previousVisits = $visitRows->filter(fn (Visit $visit): bool => CarbonImmutable::parse($visit->last_seen_at)->betweenIncluded($comparisonStart->utc(), $start->subSecond()->utc()));
        $currentVisits = $this->filterVisits($currentVisits, $current, $filters);
        $previousVisits = $this->filterVisits($previousVisits, $previous, $filters);
        $currentVisitCount = $currentVisits->count() ?: $this->estimateVisits($current);
        $previousVisitCount = $previousVisits->count() ?: $this->estimateVisits($previous);
        $goalEvents = $this->matchingGoals($current, $goals);
        $convertedVisits = $currentVisits->where('conversion_count', '>', 0)->count();
        if ($currentVisits->isEmpty()) {
            $convertedVisits = $goalEvents->pluck('visitor_hash')->filter()->unique()->count();
        }
        $conversionRate = $currentVisitCount > 0 ? round(($convertedVisits / $currentVisitCount) * 100, 1) : 0;
        $eligibleVisits = $currentVisits->filter(fn (Visit $visit): bool => CarbonImmutable::parse($visit->last_seen_at)->lte(CarbonImmutable::now()->subMinutes(30)) && $visit->pageviews > 0);
        $bounces = $eligibleVisits->where('pageviews', 1)->where('conversion_count', 0)->count();
        $bounceRate = $eligibleVisits->count() > 0 ? round(($bounces / $eligibleVisits->count()) * 100, 1) : null;
        $visitSources = $this->visitSources($currentVisits);
        $visitCampaigns = $this->visitCampaigns($currentVisits);

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
            'series' => $this->series($current, $site->timezone, $start, $end),
            'pages' => $this->ranking($current->where('type', 'pageview'), 'path'),
            'entryPages' => $this->visitRanking($currentVisits, 'landing_path'),
            'exitPages' => $this->visitRanking($currentVisits, 'exit_path'),
            'sources' => $visitSources ?: $this->sources($current),
            'devices' => $this->ranking($current, 'device_category'),
            'browsers' => $this->ranking($current, 'browser'),
            'operatingSystems' => $this->ranking($current, 'operating_system'),
            'campaigns' => $visitCampaigns ?: $this->ranking($current, 'utm_campaign'),
            'recent' => $this->recent($current),
            'filters' => $filters,
            'goals' => $goals->map(fn (Goal $goal): array => [
                'name' => $goal->name,
                'value' => $this->matchingGoals($current, collect([$goal]))->count(),
                'kind' => $goal->kind,
            ])->values()->all(),
            'lastProcessedAt' => $site->last_processed_at,
            'hasData' => $current->isNotEmpty(),
        ];
    }

    private function change(int $current, int $previous): ?string
    {
        if ($previous === 0) {
            return $current > 0 ? 'New' : null;
        }

        return sprintf('%+.1f%%', (($current - $previous) / $previous) * 100);
    }

    private function averageDailyVisitors(Collection $events, string $timezone, int $days): int
    {
        $dailyTotal = $events->groupBy(fn (AnalyticsEvent $event): string => $event->occurred_at->setTimezone($timezone)->toDateString())
            ->sum(fn (Collection $items): int => $items->pluck('visitor_hash')->filter()->unique()->count());

        return $dailyTotal > 0 ? max(1, (int) round($dailyTotal / $days)) : 0;
    }

    private function estimateVisits(Collection $events): int
    {
        $visits = 0;
        $lastSeen = [];

        foreach ($events->where('type', 'pageview')->sortBy('occurred_at') as $event) {
            $identity = $event->session_id ?: $event->visitor_hash ?: 'anonymous-'.$event->event_id;
            $last = $lastSeen[$identity] ?? null;

            if ($last === null || $event->occurred_at->getTimestamp() - $last->getTimestamp() > 1800) {
                $visits++;
            }

            $lastSeen[$identity] = $event->occurred_at;
        }

        return $visits;
    }

    private function series(Collection $events, string $timezone, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $days = [];
        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $days[$date->toDateString()] = 0;
        }

        foreach ($events->where('type', 'pageview') as $event) {
            $key = $event->occurred_at->setTimezone($timezone)->toDateString();
            if (array_key_exists($key, $days)) {
                $days[$key]++;
            }
        }

        return collect($days)->map(fn (int $value, string $date): array => ['date' => CarbonImmutable::parse($date)->format('M j'), 'value' => $value])->values()->all();
    }

    private function ranking(Collection $events, string $field): array
    {
        return $events->groupBy(fn (AnalyticsEvent $event): string => $event->{$field} ?: 'Unknown')
            ->map(fn (Collection $items, string $label): array => ['label' => $label, 'value' => $items->count()])
            ->sortByDesc('value')->take(5)->values()->all();
    }

    private function visitRanking(Collection $visits, string $field): array
    {
        return $visits->groupBy(fn (Visit $visit): string => $visit->{$field} ?: 'Unknown')
            ->map(fn (Collection $items, string $label): array => ['label' => $label, 'value' => $items->count()])
            ->sortByDesc('value')->take(5)->values()->all();
    }

    private function recent(Collection $events): array
    {
        $cutoff = CarbonImmutable::now()->subMinutes(5);
        $items = $events->filter(fn (AnalyticsEvent $event): bool => $event->occurred_at->greaterThanOrEqualTo($cutoff))
            ->sortByDesc('occurred_at')->take(8);

        return [
            'visitorCount' => $items->pluck('visitor_hash')->filter()->unique()->count(),
            'events' => $items->map(fn (AnalyticsEvent $event): array => [
                'type' => $event->type,
                'path' => $event->path,
                'occurredAt' => $event->occurred_at,
                'source' => $event->utm_source ?: ($event->referrer_host ?: 'Direct / unknown'),
            ])->values()->all(),
        ];
    }

    private function sources(Collection $events): array
    {
        return $events->where('type', 'pageview')->groupBy(function (AnalyticsEvent $event): string {
            if ($event->utm_source) {
                return $event->utm_source.($event->utm_medium ? ' / '.$event->utm_medium : '');
            }

            return $event->referrer_host ?: 'Direct / unknown';
        })->map(fn (Collection $items, string $label): array => ['label' => $label, 'value' => $items->count()])
            ->sortByDesc('value')->take(5)->values()->all();
    }

    private function visitSources(Collection $visits): array
    {
        return $visits->filter(fn (Visit $visit): bool => $visit->pageviews > 0)
            ->groupBy(function (Visit $visit): string {
                if ($visit->entry_utm_source) {
                    return $visit->entry_utm_source.($visit->entry_utm_medium ? ' / '.$visit->entry_utm_medium : '');
                }

                return $visit->entry_referrer_host ?: 'Direct / unknown';
            })
            ->map(fn (Collection $items, string $label): array => ['label' => $label, 'value' => $items->count()])
            ->sortByDesc('value')->take(5)->values()->all();
    }

    private function visitCampaigns(Collection $visits): array
    {
        return $visits->filter(fn (Visit $visit): bool => $visit->entry_utm_campaign !== null)
            ->groupBy('entry_utm_campaign')
            ->map(fn (Collection $items, string $label): array => ['label' => $label, 'value' => $items->count()])
            ->sortByDesc('value')->take(5)->values()->all();
    }

    /** @param Collection<int, Visit> $visits */
    /** @param Collection<int, AnalyticsEvent> $events */
    /** @param array<string, string|null> $filters */
    private function filterVisits(Collection $visits, Collection $events, array $filters): Collection
    {
        $filtered = $visits;

        if ($filters['source'] ?? null) {
            $source = $filters['source'];
            $filtered = $filtered->filter(fn (Visit $visit): bool => $visit->entry_utm_source === $source || $visit->entry_referrer_host === $source);
        }

        if ($filters['campaign'] ?? null) {
            $campaign = $filters['campaign'];
            $filtered = $filtered->filter(fn (Visit $visit): bool => $visit->entry_utm_campaign === $campaign);
        }

        if (($filters['path'] ?? null) || ($filters['device'] ?? null)) {
            $visitMap = $filtered->groupBy(fn (Visit $visit): string => $visit->session_id ?: $visit->visitor_hash ?: 'anonymous');
            $keys = $events->filter(function (AnalyticsEvent $event) use ($filters): bool {
                return ($filters['path'] ?? null) === null || $event->path === $filters['path'];
            })->map(fn (AnalyticsEvent $event): ?string => $this->visitForEvent($event, $visitMap))->filter()->unique();
            $filtered = $filtered->whereIn('visit_key', $keys);
        }

        return $filtered;
    }

    private function visitForEvent(AnalyticsEvent $event, Collection $visitMap): ?string
    {
        $identity = $event->session_id ?: $event->visitor_hash ?: 'anonymous';
        $occurredAt = CarbonImmutable::parse($event->occurred_at);

        return $visitMap->get($identity, collect())->first(fn (Visit $visit): bool => $occurredAt->betweenIncluded(CarbonImmutable::parse($visit->started_at), CarbonImmutable::parse($visit->last_seen_at)))?->visit_key;
    }

    private function matchingGoals(Collection $events, Collection $goals): Collection
    {
        return $events->filter(function (AnalyticsEvent $event) use ($goals): bool {
            return $goals->contains(function (Goal $goal) use ($event): bool {
                $version = $goal->versions->first(function ($version) use ($event): bool {
                    $occurredAt = CarbonImmutable::parse($event->occurred_at);

                    return $occurredAt->greaterThanOrEqualTo($version->effective_from)
                        && ($version->effective_to === null || $occurredAt->lessThan($version->effective_to));
                });

                if ($goal->versions->isNotEmpty() && $version === null) {
                    return false;
                }

                $kind = $version?->kind ?? $goal->kind;
                $matchType = $version?->match_type ?? $goal->match_type;
                $matchValue = $version?->match_value ?? $goal->match_value;

                if ($kind === 'event') {
                    return $event->type === 'event' && data_get($event->properties, 'name') === $matchValue;
                }

                return $event->type === 'pageview' && ($matchType === 'prefix'
                    ? str_starts_with($event->path, $matchValue)
                    : $event->path === $matchValue);
            });
        });
    }

    /**
     * @param  array<string, string|null>  $filters
     * @return array<string, mixed>|null
     */
    private function fromAggregates(Site $site, int $days, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $comparisonStart, array $filters): ?array
    {
        $rows = ReportDailyAggregate::query()
            ->where('site_id', $site->id)
            ->where('dimension', 'all')
            ->whereBetween('local_date', [$comparisonStart->toDateString(), $end->toDateString()])
            ->orderBy('local_date')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $current = $rows->filter(fn (ReportDailyAggregate $row): bool => CarbonImmutable::parse($row->local_date)->betweenIncluded($start->toDateString(), $end->toDateString()));
        $previous = $rows->filter(fn (ReportDailyAggregate $row): bool => CarbonImmutable::parse($row->local_date)->betweenIncluded($comparisonStart->toDateString(), $start->subDay()->toDateString()));
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
        $goals = Goal::query()->where('site_id', $site->id)->where('active', true)->get();

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
            'series' => $this->aggregateSeries($current, $start, $end),
            'pages' => $this->aggregateRanking($site, 'path', $start, $end, 'pageviews'),
            'entryPages' => [],
            'exitPages' => [],
            'sources' => $this->aggregateRanking($site, 'source', $start, $end, 'visits'),
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

    private function aggregateSum(Collection $rows, string $column): int
    {
        return (int) $rows->sum(fn (ReportDailyAggregate $row): int => (int) $row->{$column});
    }

    private function aggregateAverageVisitors(Collection $rows, int $days): int
    {
        return $rows->isEmpty() ? 0 : max(1, (int) round($this->aggregateSum($rows, 'visitors') / $days));
    }

    /** @return list<array{date: string, value: int}> */
    private function aggregateSeries(Collection $rows, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $values = $rows->keyBy(fn (ReportDailyAggregate $row): string => CarbonImmutable::parse($row->local_date)->toDateString());
        $series = [];

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $key = $date->toDateString();
            $series[] = ['date' => $date->format('M j'), 'value' => (int) ($values->get($key)->pageviews ?? 0)];
        }

        return $series;
    }

    /** @return list<array{label: string, value: int}> */
    private function aggregateRanking(Site $site, string $dimension, CarbonImmutable $start, CarbonImmutable $end, string $column): array
    {
        return ReportDailyAggregate::query()
            ->where('site_id', $site->id)
            ->where('dimension', $dimension)
            ->whereBetween('local_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy(fn (ReportDailyAggregate $row): string => $row->dimension_value ?: 'Unknown')
            ->map(fn (Collection $items, string $label): array => ['label' => $label, 'value' => (int) $items->sum($column)])
            ->sortByDesc('value')
            ->take(5)
            ->values()
            ->all();
    }
}
