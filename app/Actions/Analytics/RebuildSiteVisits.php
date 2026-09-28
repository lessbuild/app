<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Models\AnalyticsEvent;
use App\Models\AnalyticsGoal;
use App\Models\AnalyticsIngestionBatch;
use App\Models\AnalyticsSite;
use App\Models\AnalyticsVisit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class RebuildSiteVisits
{
    /**
     * Rebuild visits from events: for the whole site, or only the visits of the visitors and sessions a batch touched.
     * Old visits in scope are deleted and replaced.
     *
     * @param  AnalyticsSite  $site
     * @param  AnalyticsIngestionBatch|null  $batch
     * @return void
     */
    public function handle(AnalyticsSite $site, ?AnalyticsIngestionBatch $batch = null): void
    {
        $goals = $site->goals()->where('active', true)->with('versions')->get();

        if ($batch === null) {
            $events = AnalyticsEvent::query()->whereBelongsTo($site, 'site')->countable()->orderBy('occurred_at')->orderBy('id')->get();
            $visits = $this->group($events, $site, $goals);

            $site->visits()->delete();

            $this->insert($visits);

            return;
        }

        $batchEvents = $batch->events()->orderBy('occurred_at')->orderBy('id')->get();

        if ($batchEvents->isEmpty()) {
            return;
        }

        $identities = $batchEvents->mapWithKeys(fn (AnalyticsEvent $event): array => [$event->visitorIdentity() => true]);
        $sessionIds = $batchEvents->pluck('session_id')->filter()->unique()->values();
        $visitorHashes = $batchEvents->pluck('visitor_hash')->filter()->unique()->values();
        $eventIds = $batchEvents->pluck('event_id')->filter()->unique()->values();
        $anonymousVisitKeys = $batchEvents
            ->filter(fn (AnalyticsEvent $event): bool => $event->session_id === null && $event->visitor_hash === null)
            ->map(fn (AnalyticsEvent $event): string => $this->visitKey($event->visitorIdentity(), CarbonImmutable::parse($event->occurred_at), $site->timezone))
            ->values();

        $events = AnalyticsEvent::query()->whereBelongsTo($site, 'site')->countable($batch)
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
            ->filter(fn (AnalyticsEvent $event): bool => isset($identities[$event->visitorIdentity()]));

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

    /**
     * Insert rebuilt visits in one statement.
     *
     * @param  array<string, array<string, mixed>>  $visits
     * @return void
     */
    private function insert(array $visits): void
    {
        if ($visits !== []) {
            AnalyticsVisit::query()->insert(array_values($visits));
        }
    }

    /**
     * Build a stable key for a visit: who it belongs to, when it started, and the local day.
     *
     * @param  string  $identity
     * @param  CarbonImmutable  $occurredAt
     * @param  string  $timezone
     * @return string
     */
    private function visitKey(string $identity, CarbonImmutable $occurredAt, string $timezone): string
    {
        return hash('sha256', $identity.'|'.$occurredAt->timestamp.'|'.$occurredAt->setTimezone($timezone)->toDateString());
    }

    /**
     * Group events into visits: a new visit starts on a new local day or after 30 minutes without activity. Each visit
     * keeps its landing and exit pages, where it came from (from its first pageview), its pageview count and its goal
     * completions.
     *
     * @param  Collection<int, AnalyticsEvent>  $events
     * @param  AnalyticsSite  $site
     * @param  Collection<int, AnalyticsGoal>  $goals
     * @return array<string, array<string, mixed>>
     */
    private function group(Collection $events, AnalyticsSite $site, Collection $goals): array
    {
        $visits = [];
        $states = [];
        $now = now();

        foreach ($events as $event) {
            $occurredAt = CarbonImmutable::parse($event->occurred_at);
            $identity = $event->visitorIdentity();
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
                    'conversion_count' => $goals->contains(fn (AnalyticsGoal $goal): bool => $goal->isCompletedBy($event)) ? 1 : 0,
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
            if ($goals->contains(fn (AnalyticsGoal $goal): bool => $goal->isCompletedBy($event))) {
                $visits[$visitKey]['conversion_count']++;
            }
            $visits[$visitKey]['updated_at'] = $now;
        }

        return $visits;
    }
}
