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
     * Rebuilds the daily totals that long-range reports read, for the days a batch touched or every day the site has
     * data. Each day gets an overall row plus rows per page, device, browser, system, source and campaign.
     *
     * @param  AnalyticsSite  $site
     * @param  AnalyticsIngestionBatch|null  $batch
     * @return void
     */
    public function handle(AnalyticsSite $site, ?AnalyticsIngestionBatch $batch = null): void
    {
        $batchEvents = $batch?->events()->orderBy('occurred_at')->orderBy('id')->get() ?? collect();
        $dates = $batch === null
            ? $this->allDates($site)
            : $batchEvents->map(fn (AnalyticsEvent $event): string => $this->localDate($event->occurred_at, $site->timezone))->unique()->values();

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
            $visitMap = $this->visitMap($dayVisits);

            $rows[] = $this->row($site, $date, 'all', null, $dayEvents, $dayVisits, $goals, $now);

            foreach (['path' => 'path', 'device' => 'device_category', 'browser' => 'browser', 'operating_system' => 'operating_system'] as $dimension => $field) {
                foreach ($dayEvents->where('type', 'pageview')->groupBy(fn (AnalyticsEvent $event): string => $event->{$field} ?: 'Unknown') as $value => $items) {
                    $dimensionVisits = $this->visitsForEvents($items, $visitMap);
                    $rows[] = $this->row($site, $date, $dimension, $value, collect($items), $dimensionVisits, $goals, $now);
                }
            }

            foreach ($dayVisits->groupBy(fn (AnalyticsVisit $visit): string => $this->source($visit)) as $value => $items) {
                $sourceVisits = collect($items);
                $rows[] = $this->row($site, $date, 'source', $value, $this->eventsForVisits($dayEvents, $sourceVisits, $visitMap), $sourceVisits, $goals, $now);
            }

            foreach ($dayVisits->filter(fn (AnalyticsVisit $visit): bool => $visit->entry_utm_campaign !== null)->groupBy('entry_utm_campaign') as $value => $items) {
                $campaignVisits = collect($items);
                $rows[] = $this->row($site, $date, 'campaign', $value, $this->eventsForVisits($dayEvents, $campaignVisits, $visitMap), $campaignVisits, $goals, $now);
            }
        }

        AnalyticsDailyAggregate::query()->where('site_id', $site->id)->whereIn('local_date', $dates)->delete();

        foreach (array_chunk($rows, 500) as $chunk) {
            AnalyticsDailyAggregate::query()->upsert($chunk, ['site_id', 'local_date', 'dimension', 'dimension_value'], [
                'pageviews', 'visits', 'visitors', 'conversions', 'converted_visits', 'bounce_eligible', 'bounces', 'updated_at',
            ]);
        }
    }

    /**
     * Every local day with countable events or visits.
     *
     * @param  AnalyticsSite  $site
     * @return Collection<int, string>
     */
    private function allDates(AnalyticsSite $site): Collection
    {
        $events = $site->events()->countable()->get(['occurred_at']);
        $visits = $site->visits()->get(['started_at']);

        return $events->map(fn (AnalyticsEvent $event): string => $this->localDate($event->occurred_at, $site->timezone))
            ->merge($visits->map(fn (AnalyticsVisit $visit): string => $this->localDate($visit->started_at, $site->timezone)))
            ->unique()
            ->values();
    }

    /**
     * The countable events on the given local days.
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
     * Visits that started on the given local days.
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
     * Visits grouped by who they belong to, for matching events to visits.
     *
     * @param  Collection<int, AnalyticsVisit>  $visits
     * @return Collection<string, Collection<int, AnalyticsVisit>> keyed by session or visitor
     */
    private function visitMap(Collection $visits): Collection
    {
        return $visits->groupBy(fn (AnalyticsVisit $visit): string => $visit->session_id ?: $visit->visitor_hash ?: 'anonymous');
    }

    /**
     * The visits the events happened in.
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
     * The events that happened in the visits.
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
     * The visit an event happened in, or null.
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
     * One aggregate row: pageviews, visits, visitors, goal completions, converted visits, and bounces among visits that
     * ended at least 30 minutes ago.
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
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * A time's date in the site's timezone.
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
     * Where a visit came from, labelled the same way the report labels sources.
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
