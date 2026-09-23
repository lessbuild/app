<?php

namespace App\Modules\Analytics\Actions\Collection;

use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\Goal;
use App\Modules\Analytics\Models\IngestionBatch;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class RebuildSiteVisits
{
    public function handle(Site $site, ?IngestionBatch $batch = null): void
    {
        $goals = $site->goals()->where('active', true)->with('versions')->get();

        if ($batch === null) {
            $events = $this->eligibleEvents($site)->orderBy('occurred_at')->orderBy('id')->get();
            $visits = $this->group($events, $site, $goals);

            $site->visits()->delete();

            $this->insert($visits);

            return;
        }

        $batchEvents = $batch->events()->orderBy('occurred_at')->orderBy('id')->get();

        if ($batchEvents->isEmpty()) {
            return;
        }

        $identities = $batchEvents->mapWithKeys(fn (AnalyticsEvent $event): array => [$this->identity($event) => true]);
        $sessionIds = $batchEvents->pluck('session_id')->filter()->unique()->values();
        $visitorHashes = $batchEvents->pluck('visitor_hash')->filter()->unique()->values();
        $eventIds = $batchEvents->pluck('event_id')->filter()->unique()->values();
        $anonymousVisitKeys = $batchEvents
            ->filter(fn (AnalyticsEvent $event): bool => $event->session_id === null && $event->visitor_hash === null)
            ->map(fn (AnalyticsEvent $event): string => $this->visitKey($this->identity($event), CarbonImmutable::parse($event->occurred_at), $site->timezone))
            ->values();

        $events = $this->eligibleEvents($site, $batch)
            ->where(function ($query) use ($sessionIds, $visitorHashes, $eventIds): void {
                $query->whereIn('event_id', $eventIds);

                if ($sessionIds->isNotEmpty()) {
                    $query->orWhereIn('session_id', $sessionIds);
                }

                if ($visitorHashes->isNotEmpty()) {
                    $query->orWhere(function ($visitorQuery) use ($visitorHashes): void {
                        $visitorQuery->whereNull('session_id')->whereIn('visitor_hash', $visitorHashes);
                    });
                }
            })
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (AnalyticsEvent $event): bool => isset($identities[$this->identity($event)]));

        $visits = $this->group($events, $site, $goals);

        $site->visits()
            ->where(function ($query) use ($sessionIds, $visitorHashes, $anonymousVisitKeys): void {
                $query->whereIn('visit_key', $anonymousVisitKeys);

                if ($sessionIds->isNotEmpty()) {
                    $query->orWhereIn('session_id', $sessionIds);
                }

                if ($visitorHashes->isNotEmpty()) {
                    $query->orWhere(function ($visitorQuery) use ($visitorHashes): void {
                        $visitorQuery->whereNull('session_id')->whereIn('visitor_hash', $visitorHashes);
                    });
                }
            })
            ->delete();

        $this->insert($visits);
    }

    private function insert(array $visits): void
    {
        if ($visits !== []) {
            Visit::query()->insert(array_values($visits));
        }
    }

    private function eligibleEvents(Site $site, ?IngestionBatch $batch = null)
    {
        return AnalyticsEvent::query()
            ->whereBelongsTo($site)
            ->where(function ($query) use ($batch): void {
                $query->whereNull('ingestion_batch_id')
                    ->orWhereHas('ingestionBatch', fn ($batchQuery) => $batchQuery->where('status', 'processed'));

                if ($batch !== null) {
                    $query->orWhere('ingestion_batch_id', $batch->id);
                }
            });
    }

    private function identity(AnalyticsEvent $event): string
    {
        return $event->session_id ?: $event->visitor_hash ?: 'anonymous-'.$event->event_id;
    }

    private function visitKey(string $identity, CarbonImmutable $occurredAt, string $timezone): string
    {
        return hash('sha256', $identity.'|'.$occurredAt->timestamp.'|'.$occurredAt->setTimezone($timezone)->toDateString());
    }

    /**
     * @param  Collection<int, AnalyticsEvent>  $events
     * @return array<string, array<string, mixed>>
     */
    private function group(Collection $events, Site $site, Collection $goals): array
    {
        $visits = [];
        $states = [];
        $now = now();

        foreach ($events as $event) {
            $occurredAt = CarbonImmutable::parse($event->occurred_at);
            $identity = $this->identity($event);
            $localDate = $occurredAt->setTimezone($site->timezone)->toDateString();
            $state = $states[$identity] ?? null;
            $newVisit = $state === null
                || $state['local_date'] !== $localDate
                || ($occurredAt->getTimestamp() - $state['last_seen']->getTimestamp()) > 1800;

            if ($newVisit) {
                $visitKey = $this->visitKey($identity, $occurredAt, $site->timezone);
                $visits[$visitKey] = [
                    'site_id' => $event->site_id,
                    'visit_key' => $visitKey,
                    'visitor_hash' => $event->visitor_hash,
                    'session_id' => $event->session_id,
                    'started_at' => $occurredAt,
                    'last_seen_at' => $occurredAt,
                    'landing_path' => $event->type === 'pageview' ? $event->path : null,
                    'exit_path' => $event->type === 'pageview' ? $event->path : null,
                    'entry_referrer_host' => $event->type === 'pageview' ? $event->referrer_host : null,
                    'entry_utm_source' => $event->type === 'pageview' ? $event->utm_source : null,
                    'entry_utm_medium' => $event->type === 'pageview' ? $event->utm_medium : null,
                    'entry_utm_campaign' => $event->type === 'pageview' ? $event->utm_campaign : null,
                    'pageviews' => $event->type === 'pageview' ? 1 : 0,
                    'conversion_count' => $goals->contains(fn (Goal $goal): bool => $this->matchesGoal($event, $goal)) ? 1 : 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $states[$identity] = ['local_date' => $localDate, 'last_seen' => $occurredAt, 'visit_key' => $visitKey];

                continue;
            }

            $visitKey = $state['visit_key'];
            $states[$identity]['last_seen'] = $occurredAt;
            $visits[$visitKey]['last_seen_at'] = $occurredAt;
            if ($event->type === 'pageview') {
                $visits[$visitKey]['landing_path'] ??= $event->path;
                $visits[$visitKey]['exit_path'] = $event->path;
                if ($visits[$visitKey]['landing_path'] === $event->path && $visits[$visitKey]['pageviews'] === 0) {
                    $visits[$visitKey]['entry_referrer_host'] = $event->referrer_host;
                    $visits[$visitKey]['entry_utm_source'] = $event->utm_source;
                    $visits[$visitKey]['entry_utm_medium'] = $event->utm_medium;
                    $visits[$visitKey]['entry_utm_campaign'] = $event->utm_campaign;
                }
                $visits[$visitKey]['pageviews']++;
            }
            if ($goals->contains(fn (Goal $goal): bool => $this->matchesGoal($event, $goal))) {
                $visits[$visitKey]['conversion_count']++;
            }
            $visits[$visitKey]['updated_at'] = $now;
        }

        return $visits;
    }

    private function matchesGoal(AnalyticsEvent $event, Goal $goal): bool
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
