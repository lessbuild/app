<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\AnalyticsDailyAggregate;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsGoal;
use App\Models\AnalyticsIngestionBatch;
use App\Models\AnalyticsSite;
use App\Models\AnalyticsVisit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class RebuildReportAggregates
{
    /**
     * The local days a batch leaves alone: today and yesterday change all the time, so the scheduled refresh
     * (RefreshRecentAggregates) rebuilds them every few minutes instead of every batch.
     *
     * @var int
     */
    public const RECENT_DAYS = 2;

    /**
     * Rebuild the daily totals that long-range reports read: for the older days a batch touched, for the given days,
     * or for every day the site has data (a week at a time, so memory stays bounded). Each day gets an overall row plus
     * rows per page, device, browser, system, source, country and campaign.
     *
     * @param  AnalyticsSite  $site
     * @param  AnalyticsIngestionBatch|null  $batch
     * @param  list<string>|null  $dates  local dates (Y-m-d) to rebuild instead
     * @return void
     */
    public function handle(AnalyticsSite $site, ?AnalyticsIngestionBatch $batch = null, ?array $dates = null): void
    {
        if ($dates !== null) {
            $this->rebuild($site, collect($dates), null);

            return;
        }
        if ($batch === null) {
            $this->allDates($site)->chunk(7)->each(fn (Collection $week) => $this->rebuild($site, $week->values(), null));

            return;
        }
        $recent = CarbonImmutable::now($site->timezone)->subDays(self::RECENT_DAYS - 1)->toDateString();
        $dates = $batch->events()->toBase()->pluck('occurred_at')
            ->map(fn (mixed $at): string => $this->localDate($at, $site->timezone))
            ->unique()
            ->filter(fn (string $date): bool => $date < $recent)
            ->values();
        $this->rebuild($site, $dates, $batch);
    }

    /**
     * Rebuild the totals for some local days.
     *
     * @param  AnalyticsSite  $site
     * @param  Collection<int, string>  $dates
     * @param  AnalyticsIngestionBatch|null  $batch  a batch being processed, whose events count already
     * @return void
     */
    private function rebuild(AnalyticsSite $site, Collection $dates, ?AnalyticsIngestionBatch $batch): void
    {
        if ($dates->isEmpty()) {
            return;
        }

        $events = $this->eventsForDates($site, $dates, $batch);
        $visits = $this->visitsForDates($site, $dates);
        $goals = $site->goals()->where('active', true)->with('versions')->get();
        $now = CarbonImmutable::now();
        $rows = [];

        foreach ($dates as $date) {
            $dayEvents = $events->filter(fn (AnalyticsEvent $event): bool => $this->localDate($event->occurred_at, $site->timezone) === $date);
            $dayVisits = $visits->filter(fn (AnalyticsVisit $visit): bool => $this->localDate($visit->started_at, $site->timezone) === $date);
            if ($dayEvents->isEmpty() && $dayVisits->isEmpty()) {
                continue;
            }
            $visitMap = $this->visitMap($dayVisits);

            $rows[] = $this->row($site, $date, 'all', null, $dayEvents, $dayVisits, $goals, $now);

            foreach (['path' => 'path', 'device' => 'device_category', 'browser' => 'browser', 'operating_system' => 'operating_system', 'screen_size' => 'screen_size'] as $dimension => $field) {
                foreach ($dayEvents->where('type', 'pageview')->groupBy(fn (AnalyticsEvent $event): string => $event->{$field} ?: 'Unknown') as $value => $items) {
                    $dimensionVisits = $this->visitsForEvents($items, $visitMap);
                    $rows[] = $this->row($site, $date, $dimension, $value, collect($items), $dimensionVisits, $goals, $now);
                }
            }

            foreach ($dayVisits->groupBy(fn (AnalyticsVisit $visit): string => $this->source($visit)) as $value => $items) {
                $sourceVisits = collect($items);
                $rows[] = $this->row($site, $date, 'source', $value, $this->eventsForVisits($dayEvents, $sourceVisits, $visitMap), $sourceVisits, $goals, $now);
            }

            foreach ($dayVisits->groupBy(fn (AnalyticsVisit $visit): string => $visit->country_code ?: 'Unknown') as $value => $items) {
                $countryVisits = collect($items);
                $rows[] = $this->row($site, $date, 'country', $value, $this->eventsForVisits($dayEvents, $countryVisits, $visitMap), $countryVisits, $goals, $now);
            }

            foreach (['channel' => 'entry_channel', 'city' => 'city'] as $dimension => $field) {
                foreach ($dayVisits->groupBy(fn (AnalyticsVisit $visit): string => $visit->{$field} ?: 'Unknown') as $value => $items) {
                    $dimensionVisits = collect($items);
                    $rows[] = $this->row($site, $date, $dimension, $value, $this->eventsForVisits($dayEvents, $dimensionVisits, $visitMap), $dimensionVisits, $goals, $now);
                }
            }

            foreach ($dayVisits->filter(fn (AnalyticsVisit $visit): bool => $visit->entry_utm_campaign !== null)->groupBy('entry_utm_campaign') as $value => $items) {
                $campaignVisits = collect($items);
                $rows[] = $this->row($site, $date, 'campaign', $value, $this->eventsForVisits($dayEvents, $campaignVisits, $visitMap), $campaignVisits, $goals, $now);
            }
        }

        AnalyticsDailyAggregate::query()->where('site_id', $site->id)->whereIn('local_date', $dates)->delete();

        foreach (array_chunk($rows, 500) as $chunk) {
            AnalyticsDailyAggregate::query()->upsert($chunk, ['site_id', 'local_date', 'dimension', 'dimension_value'], [
                'pageviews', 'visits', 'visitors', 'conversions', 'converted_visits', 'bounce_eligible', 'bounces', 'duration_seconds', 'updated_at',
            ]);
        }
    }

    /**
     * Get every local day between the site's first and last event, oldest first.
     *
     * @param  AnalyticsSite  $site
     * @return Collection<int, string>
     */
    private function allDates(AnalyticsSite $site): Collection
    {
        $range = $site->events()->toBase()->selectRaw('MIN(occurred_at) AS first_at, MAX(occurred_at) AS last_at')->first();
        if ($range === null || $range->first_at === null) {
            return collect();
        }
        $dates = collect();
        $last = $this->localDate($range->last_at, $site->timezone);
        for ($date = CarbonImmutable::parse($this->localDate($range->first_at, $site->timezone)); $date->toDateString() <= $last; $date = $date->addDay()) {
            $dates->push($date->toDateString());
        }

        return $dates;
    }

    /**
     * Get the countable events on the given local days.
     *
     * @param  AnalyticsSite  $site
     * @param  Collection<int, string>  $dates
     * @param  AnalyticsIngestionBatch|null  $batch
     * @return Collection<int, AnalyticsEvent>
     */
    private function eventsForDates(AnalyticsSite $site, Collection $dates, ?AnalyticsIngestionBatch $batch): Collection
    {
        return $site->events()->countable($batch)
            ->where(function (Builder $query) use ($dates, $site): void {
                foreach ($dates as $date) {
                    $start = CarbonImmutable::parse($date, $site->timezone)->startOfDay()->utc();
                    $end = CarbonImmutable::parse($date, $site->timezone)->endOfDay()->utc();
                    $query->orWhereBetween('occurred_at', [$start, $end]);
                }
            })
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Get the visits that started on the given local days.
     *
     * @param  AnalyticsSite  $site
     * @param  Collection<int, string>  $dates
     * @return Collection<int, AnalyticsVisit>
     */
    private function visitsForDates(AnalyticsSite $site, Collection $dates): Collection
    {
        return $site->visits()->where(function (Builder $query) use ($dates, $site): void {
            foreach ($dates as $date) {
                $start = CarbonImmutable::parse($date, $site->timezone)->startOfDay()->utc();
                $end = CarbonImmutable::parse($date, $site->timezone)->endOfDay()->utc();
                $query->orWhereBetween('started_at', [$start, $end]);
            }
        })->get();
    }

    /**
     * Group visits by who they belong to, for matching events to visits.
     *
     * @param  Collection<int, AnalyticsVisit>  $visits
     * @return Collection<string, Collection<int, AnalyticsVisit>> keyed by session or visitor
     */
    private function visitMap(Collection $visits): Collection
    {
        return $visits->groupBy(fn (AnalyticsVisit $visit): string => $visit->session_id ?: $visit->visitor_hash ?: 'anonymous');
    }

    /**
     * Get the visits the events happened in.
     *
     * @param  Collection<int, AnalyticsEvent>  $events
     * @param  Collection<string, Collection<int, AnalyticsVisit>>  $visitMap
     * @return Collection<int, AnalyticsVisit>
     */
    private function visitsForEvents(Collection $events, Collection $visitMap): Collection
    {
        $keys = $events->map(fn (AnalyticsEvent $event): ?string => $this->visitForEvent($event, $visitMap)?->visit_key)
            ->filter()
            ->unique();

        return $visitMap->flatMap(fn (Collection $visits): Collection => $visits)->whereIn('visit_key', $keys)->values();
    }

    /**
     * Get the events that happened in the visits.
     *
     * @param  Collection<int, AnalyticsEvent>  $events
     * @param  Collection<int, AnalyticsVisit>  $visits
     * @param  Collection<string, Collection<int, AnalyticsVisit>>  $visitMap
     * @return Collection<int, AnalyticsEvent>
     */
    private function eventsForVisits(Collection $events, Collection $visits, Collection $visitMap): Collection
    {
        $keys = $visits->pluck('visit_key');

        return $events->filter(fn (AnalyticsEvent $event): bool => $keys->contains($this->visitForEvent($event, $visitMap)?->visit_key));
    }

    /**
     * Find the visit an event happened in, or null.
     *
     * @param  AnalyticsEvent  $event
     * @param  Collection<string, Collection<int, AnalyticsVisit>>  $visitMap
     * @return AnalyticsVisit|null
     */
    private function visitForEvent(AnalyticsEvent $event, Collection $visitMap): ?AnalyticsVisit
    {
        $identity = $event->session_id ?: $event->visitor_hash ?: 'anonymous';
        $occurredAt = CarbonImmutable::parse($event->occurred_at);

        return $visitMap->get($identity, collect())->first(fn (AnalyticsVisit $visit): bool => $occurredAt->betweenIncluded($visit->started_at, $visit->last_seen_at));
    }

    /**
     * Build one aggregate row: pageviews, visits, visitors, goal completions, converted visits, bounces among visits
     * that ended at least 30 minutes ago, and the visits' total length.
     *
     * @param  AnalyticsSite  $site
     * @param  string  $date
     * @param  string  $dimension
     * @param  string|null  $value
     * @param  Collection<int, AnalyticsEvent>  $events
     * @param  Collection<int, AnalyticsVisit>  $visits
     * @param  Collection<int, AnalyticsGoal>  $goals
     * @param  CarbonImmutable  $now
     * @return array<string, mixed>
     */
    private function row(AnalyticsSite $site, string $date, string $dimension, ?string $value, Collection $events, Collection $visits, Collection $goals, CarbonImmutable $now): array
    {
        $pageviews = $events->where('type', 'pageview')->count();
        $matching = $events->filter(fn (AnalyticsEvent $event): bool => $goals->contains(fn (AnalyticsGoal $goal): bool => $goal->isCompletedBy($event)))->count();
        $eligible = $visits->filter(fn (AnalyticsVisit $visit): bool => $visit->pageviews > 0 && CarbonImmutable::parse($visit->last_seen_at)->lte($now->subMinutes(30)));
        $visitorCount = $visits->pluck('visitor_hash')->filter()->unique()->count();

        if ($visitorCount === 0) {
            $visitorCount = $events->pluck('visitor_hash')->filter()->unique()->count();
        }

        return [
            'site_id' => $site->id,
            'local_date' => $date,
            'dimension' => $dimension,
            'dimension_value' => $value,
            'pageviews' => $pageviews,
            'visits' => $visits->count(),
            'visitors' => $visitorCount,
            'conversions' => $matching,
            'converted_visits' => $visits->where('conversion_count', '>', 0)->count(),
            'bounce_eligible' => $eligible->count(),
            'bounces' => $eligible->where('pageviews', 1)->where('conversion_count', 0)->count(),
            'duration_seconds' => (int) $visits->sum(fn (AnalyticsVisit $visit): int => max(0, (int) CarbonImmutable::parse($visit->started_at)->diffInSeconds(CarbonImmutable::parse($visit->last_seen_at)))),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Get a time's date in the site's timezone.
     *
     * @param  mixed  $value
     * @param  string  $timezone
     * @return string
     */
    private function localDate(mixed $value, string $timezone): string
    {
        return CarbonImmutable::parse($value)->setTimezone($timezone)->toDateString();
    }

    /**
     * Label where a visit came from, the same way the report labels sources.
     *
     * @param  AnalyticsVisit  $visit
     * @return string
     */
    private function source(AnalyticsVisit $visit): string
    {
        return $visit->entry_utm_source
            ? $visit->entry_utm_source.($visit->entry_utm_medium ? ' / '.$visit->entry_utm_medium : '')
            : ($visit->entry_referrer_host ?: 'Direct / unknown');
    }
}
