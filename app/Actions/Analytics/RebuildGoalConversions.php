<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsGoalConversion;
use App\Models\AnalyticsIngestionBatch;
use App\Models\AnalyticsSite;
use App\Models\AnalyticsVisit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Materialize the event-level conversion audit trail for a site.
 *
 * Visits and report aggregates contain denormalized counters for fast reads,
 * while this table keeps the goal version and source event that produced each
 * conversion. Batch rebuilds only revisit identities touched by the batch;
 * goal edits call this action without a batch and therefore rebuild history.
 */
final class RebuildGoalConversions
{
    /**
     * Rebuild the goal conversions for the events a batch touched, or for the whole site when no batch is given (after
     * a goal changes). Existing conversions in scope are replaced.
     *
     * @param  AnalyticsSite  $site
     * @param  AnalyticsIngestionBatch|null  $batch
     * @return void
     */
    public function handle(AnalyticsSite $site, ?AnalyticsIngestionBatch $batch = null): void
    {
        $goals = $site->goals()->with('versions')->get();
        $events = $this->eventsForScope($site, $batch);

        if ($batch === null) {
            $site->goalConversions()->delete();
        } elseif ($events->isNotEmpty()) {
            AnalyticsGoalConversion::query()
                ->where('site_id', $site->id)
                ->whereIn('analytics_event_id', $events->pluck('id'))
                ->delete();
        }

        if ($events->isEmpty() || $goals->isEmpty()) {
            return;
        }

        $visits = $site->visits()->get();
        $rows = [];
        $now = CarbonImmutable::now();

        foreach ($events as $event) {
            foreach ($goals as $goal) {
                $version = $goal->versionAt($event->occurred_at);

                if (! $goal->isCompletedBy($event)) {
                    continue;
                }

                $visit = $this->visitForEvent($event, $visits, $site->timezone);
                $rows[] = [
                    'site_id' => $site->id,
                    'goal_id' => $goal->id,
                    'goal_version_id' => $version?->id,
                    'analytics_event_id' => $event->id,
                    'visit_id' => $visit?->id,
                    'converted_at' => $event->occurred_at,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            AnalyticsGoalConversion::query()->insertOrIgnore($chunk);
        }
    }

    /**
     * Get the events to recount: the whole site's countable events, or, for a batch, every countable event of the
     * visitors and sessions the batch contains, since a new event can change their earlier visits.
     *
     * @param  AnalyticsSite  $site
     * @param  AnalyticsIngestionBatch|null  $batch
     * @return Collection<int, AnalyticsEvent>
     */
    private function eventsForScope(AnalyticsSite $site, ?AnalyticsIngestionBatch $batch): Collection
    {
        $query = AnalyticsEvent::query()->whereBelongsTo($site, 'site')->countable($batch)
            ->orderBy('occurred_at')
            ->orderBy('id');

        if ($batch === null) {
            return $query->get();
        }

        $batchEvents = $batch->events()->get();

        if ($batchEvents->isEmpty()) {
            return collect();
        }

        $identities = $batchEvents
            ->mapWithKeys(fn (AnalyticsEvent $event): array => [$event->visitorIdentity() => true]);

        return $query
            ->where(function (Builder $scope) use ($batchEvents): void {
                $scope->whereIn('id', $batchEvents->pluck('id'));

                $sessionIds = $batchEvents->pluck('session_id')->filter()->unique();
                $visitorHashes = $batchEvents->pluck('visitor_hash')->filter()->unique();

                if ($sessionIds->isNotEmpty()) {
                    $scope->orWhereIn('session_id', $sessionIds);
                }

                if ($visitorHashes->isNotEmpty()) {
                    $scope->orWhere(function (Builder $visitorScope) use ($visitorHashes): void {
                        $visitorScope->whereNull('session_id')->whereIn('visitor_hash', $visitorHashes);
                    });
                }
            })
            ->get()
            ->filter(fn (AnalyticsEvent $event): bool => isset($identities[$event->visitorIdentity()]))
            ->values();
    }

    /**
     * Find the visit an event happened in: same visitor or session, same local day, and within the visit's time span.
     *
     * @param  AnalyticsEvent  $event
     * @param  Collection<int, AnalyticsVisit>  $visits
     * @param  string  $timezone
     * @return AnalyticsVisit|null
     */
    private function visitForEvent(AnalyticsEvent $event, Collection $visits, string $timezone): ?AnalyticsVisit
    {
        $identity = $event->visitorIdentity();
        $occurredAt = CarbonImmutable::parse($event->occurred_at);

        return $visits->first(function (AnalyticsVisit $visit) use ($identity, $occurredAt, $timezone): bool {
            $visitIdentity = $visit->session_id ?: $visit->visitor_hash ?: 'anonymous';

            return $visitIdentity === $identity
                && CarbonImmutable::parse($visit->started_at)->setTimezone($timezone)->toDateString()
                    === $occurredAt->setTimezone($timezone)->toDateString()
                && $occurredAt->betweenIncluded($visit->started_at, $visit->last_seen_at);
        });
    }
}
