<?php

namespace App\Modules\Analytics\Actions\Reporting;

use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\Goal;
use App\Modules\Analytics\Models\IngestionBatch;
use App\Modules\Analytics\Models\ReportDailyAggregate;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

final class RebuildReportAggregates
{
    public function handle(Site $site, ?IngestionBatch $batch = null): void
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
            $dayVisits = $visits->filter(fn (Visit $visit): bool => $this->localDate($visit->started_at, $site->timezone) === $date);
            $visitMap = $this->visitMap($dayVisits);

            $rows[] = $this->row($site, $date, 'all', null, $dayEvents, $dayVisits, $goals, $now);

            foreach (['path' => 'path', 'device' => 'device_category', 'browser' => 'browser', 'operating_system' => 'operating_system'] as $dimension => $field) {
                foreach ($dayEvents->where('type', 'pageview')->groupBy(fn (AnalyticsEvent $event): string => $event->{$field} ?: 'Unknown') as $value => $items) {
                    $dimensionVisits = $this->visitsForEvents($items, $visitMap);
                    $rows[] = $this->row($site, $date, $dimension, $value, collect($items), $dimensionVisits, $goals, $now);
                }
            }

            foreach ($dayVisits->groupBy(fn (Visit $visit): string => $this->source($visit)) as $value => $items) {
                $sourceVisits = collect($items);
                $rows[] = $this->row($site, $date, 'source', $value, $this->eventsForVisits($dayEvents, $sourceVisits, $visitMap), $sourceVisits, $goals, $now);
            }

            foreach ($dayVisits->filter(fn (Visit $visit): bool => $visit->entry_utm_campaign !== null)->groupBy('entry_utm_campaign') as $value => $items) {
                $campaignVisits = collect($items);
                $rows[] = $this->row($site, $date, 'campaign', $value, $this->eventsForVisits($dayEvents, $campaignVisits, $visitMap), $campaignVisits, $goals, $now);
            }
        }

        ReportDailyAggregate::query()->where('site_id', $site->id)->whereIn('local_date', $dates)->delete();

        foreach (array_chunk($rows, 500) as $chunk) {
            ReportDailyAggregate::query()->upsert($chunk, ['site_id', 'local_date', 'dimension', 'dimension_value'], [
                'pageviews', 'visits', 'visitors', 'conversions', 'converted_visits', 'bounce_eligible', 'bounces', 'updated_at',
            ]);
        }
    }

    /** @return Collection<int, string> */
    private function allDates(Site $site): Collection
    {
        $events = $this->eligibleEvents($site)->get(['occurred_at']);
        $visits = $site->visits()->get(['started_at']);

        return $events->map(fn (AnalyticsEvent $event): string => $this->localDate($event->occurred_at, $site->timezone))
            ->merge($visits->map(fn (Visit $visit): string => $this->localDate($visit->started_at, $site->timezone)))
            ->unique()
            ->values();
    }

    /** @param Collection<int, string> $dates */
    private function eventsForDates(Site $site, Collection $dates, ?IngestionBatch $batch): Collection
    {
        return $this->eligibleEvents($site, $batch)
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

    /** @param Collection<int, string> $dates */
    private function visitsForDates(Site $site, Collection $dates): Collection
    {
        return $site->visits()->where(function (Builder $query) use ($dates, $site): void {
            foreach ($dates as $date) {
                $start = CarbonImmutable::parse($date, $site->timezone)->startOfDay()->utc();
                $end = CarbonImmutable::parse($date, $site->timezone)->endOfDay()->utc();
                $query->orWhereBetween('started_at', [$start, $end]);
            }
        })->get();
    }

    private function eligibleEvents(Site $site, ?IngestionBatch $batch = null): HasMany
    {
        return $site->events()
            ->where(function (Builder $query) use ($batch): void {
                $query->whereNull('ingestion_batch_id')
                    ->orWhereHas('ingestionBatch', fn (Builder $batchQuery) => $batchQuery->where('status', 'processed'));

                if ($batch !== null) {
                    $query->orWhere('ingestion_batch_id', $batch->id);
                }
            });
    }

    /** @param Collection<int, Visit> $visits */
    private function visitMap(Collection $visits): Collection
    {
        return $visits->groupBy(fn (Visit $visit): string => $visit->session_id ?: $visit->visitor_hash ?: 'anonymous');
    }

    /** @param Collection<int, AnalyticsEvent> $events */
    private function visitsForEvents(Collection $events, Collection $visitMap): Collection
    {
        $keys = $events->map(fn (AnalyticsEvent $event): ?string => $this->visitForEvent($event, $visitMap)?->visit_key)
            ->filter()
            ->unique();

        return $visitMap->flatten(1)->whereIn('visit_key', $keys)->values();
    }

    /** @param Collection<int, AnalyticsEvent> $events */
    /** @param Collection<int, Visit> $visits */
    private function eventsForVisits(Collection $events, Collection $visits, Collection $visitMap): Collection
    {
        $keys = $visits->pluck('visit_key');

        return $events->filter(fn (AnalyticsEvent $event): bool => $keys->contains($this->visitForEvent($event, $visitMap)?->visit_key));
    }

    private function visitForEvent(AnalyticsEvent $event, Collection $visitMap): ?Visit
    {
        $identity = $event->session_id ?: $event->visitor_hash ?: 'anonymous';
        $occurredAt = CarbonImmutable::parse($event->occurred_at);

        return $visitMap->get($identity, collect())->first(fn (Visit $visit): bool => $occurredAt->betweenIncluded($visit->started_at, $visit->last_seen_at));
    }

    /** @param Collection<int, AnalyticsEvent> $events */
    /** @param Collection<int, Visit> $visits */
    /** @param Collection<int, Goal> $goals */
    /** @return array<string, mixed> */
    private function row(Site $site, string $date, string $dimension, ?string $value, Collection $events, Collection $visits, Collection $goals, CarbonImmutable $now): array
    {
        $pageviews = $events->where('type', 'pageview')->count();
        $matching = $events->filter(fn (AnalyticsEvent $event): bool => $goals->contains(fn (Goal $goal): bool => $this->matches($event, $goal)))->count();
        $eligible = $visits->filter(fn (Visit $visit): bool => $visit->pageviews > 0 && CarbonImmutable::parse($visit->last_seen_at)->lte($now->subMinutes(30)));
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

    private function localDate(mixed $value, string $timezone): string
    {
        return CarbonImmutable::parse($value)->setTimezone($timezone)->toDateString();
    }

    private function source(Visit $visit): string
    {
        return $visit->entry_utm_source
            ? $visit->entry_utm_source.($visit->entry_utm_medium ? ' / '.$visit->entry_utm_medium : '')
            : ($visit->entry_referrer_host ?: 'Direct / unknown');
    }

    private function matches(AnalyticsEvent $event, Goal $goal): bool
    {
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
    }
}
