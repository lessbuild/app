<?php

namespace App\Modules\Analytics\Actions\Goals;

use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\Goal;
use App\Modules\Analytics\Models\GoalConversion;
use App\Modules\Analytics\Models\IngestionBatch;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\Visit;
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
    public function handle(Site $site, ?IngestionBatch $batch = null): void
    {
        $goals = $site->goals()->with('versions')->get();
        $events = $this->eventsForScope($site, $batch);

        if ($batch === null) {
            $site->goalConversions()->delete();
        } elseif ($events->isNotEmpty()) {
            GoalConversion::query()
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
                $version = $this->matchingVersion($event, $goal);

                if (! $this->matchesGoal($event, $goal)) {
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
            GoalConversion::query()->insertOrIgnore($chunk);
        }
    }

    /** @return Collection<int, AnalyticsEvent> */
    private function eventsForScope(Site $site, ?IngestionBatch $batch): Collection
    {
        $query = $this->eligibleEvents($site, $batch)
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
            ->mapWithKeys(fn (AnalyticsEvent $event): array => [$this->identity($event) => true]);

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
            ->filter(fn (AnalyticsEvent $event): bool => isset($identities[$this->identity($event)]))
            ->values();
    }

    private function eligibleEvents(Site $site, ?IngestionBatch $batch): Builder
    {
        return AnalyticsEvent::query()
            ->whereBelongsTo($site)
            ->where(function (Builder $query) use ($batch): void {
                $query->whereNull('ingestion_batch_id')
                    ->orWhereHas('ingestionBatch', fn (Builder $batchQuery) => $batchQuery->where('status', 'processed'));

                if ($batch !== null) {
                    $query->orWhere('ingestion_batch_id', $batch->id);
                }
            });
    }

    private function identity(AnalyticsEvent $event): string
    {
        return $event->session_id ?: $event->visitor_hash ?: 'anonymous-'.$event->event_id;
    }

    private function visitForEvent(AnalyticsEvent $event, Collection $visits, string $timezone): ?Visit
    {
        $identity = $this->identity($event);
        $occurredAt = CarbonImmutable::parse($event->occurred_at);

        return $visits->first(function (Visit $visit) use ($identity, $occurredAt, $timezone): bool {
            $visitIdentity = $visit->session_id ?: $visit->visitor_hash ?: 'anonymous';

            return $visitIdentity === $identity
                && CarbonImmutable::parse($visit->started_at)->setTimezone($timezone)->toDateString()
                    === $occurredAt->setTimezone($timezone)->toDateString()
                && $occurredAt->betweenIncluded($visit->started_at, $visit->last_seen_at);
        });
    }

    private function matchingVersion(AnalyticsEvent $event, Goal $goal): ?object
    {
        $occurredAt = CarbonImmutable::parse($event->occurred_at);

        return $goal->versions->first(function (object $version) use ($occurredAt): bool {
            return $occurredAt->greaterThanOrEqualTo($version->effective_from)
                && ($version->effective_to === null || $occurredAt->lessThan($version->effective_to));
        });
    }

    private function matchesGoal(AnalyticsEvent $event, Goal $goal): bool
    {
        $version = $this->matchingVersion($event, $goal);

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
