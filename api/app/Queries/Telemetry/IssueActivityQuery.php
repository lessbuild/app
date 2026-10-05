<?php

declare(strict_types=1);

namespace App\Queries\Telemetry;

use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\Project;
use App\Models\TelemetryEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class IssueActivityQuery
{
    /**
     * Sum up a project's issues: how many are open, how many were first seen today, their occurrences over the last
     * day by the hour (with the day before, for a trend), and how many people the open ones affected.
     *
     * @param  Project  $project
     * @return array{open: int, newToday: int, events: int, eventsBefore: int, hourly: list<int>, users: int}
     */
    public function stats(Project $project): array
    {
        $now = CarbonImmutable::now('UTC');
        $issues = Issue::query()->where('project_id', $project->id);
        $occurred = TelemetryEvent::query()->whereIn('issue_id', Issue::query()->where('project_id', $project->id)->select('id'))
            ->where('occurred_at', '>=', $now->subHours(48))->pluck('occurred_at');

        return [
            'open' => (clone $issues)->where('status', IssueStatus::Open)->count(),
            'newToday' => (clone $issues)->where('first_seen_at', '>=', $now->startOfDay())->count(),
            'events' => $occurred->filter(fn ($at): bool => CarbonImmutable::parse($at)->greaterThanOrEqualTo($now->subDay()))->count(),
            'eventsBefore' => $occurred->filter(fn ($at): bool => CarbonImmutable::parse($at)->lessThan($now->subDay()))->count(),
            'hourly' => $this->hourly($occurred, $now, 24),
            'users' => (int) (clone $issues)->where('status', IssueStatus::Open)->sum('affected_users'),
        ];
    }

    /**
     * Count each issue's occurrences in each of the last hours, oldest first.
     *
     * @param  list<int>  $issueIds
     * @param  int  $hours
     * @return array<int, list<int>>
     */
    public function trends(array $issueIds, int $hours = 12): array
    {
        if ($issueIds === []) {
            return [];
        }
        $now = CarbonImmutable::now('UTC');
        $events = TelemetryEvent::query()->whereIn('issue_id', $issueIds)->where('occurred_at', '>=', $now->subHours($hours))->get(['issue_id', 'occurred_at'])->groupBy('issue_id');

        $trends = [];
        foreach ($issueIds as $id) {
            /** @var Collection<int, TelemetryEvent> $own */
            $own = $events->get($id) ?? collect();
            $trends[$id] = $this->hourly($own->pluck('occurred_at'), $now, $hours);
        }

        return $trends;
    }

    /**
     * Describe where an issue happens and how it started: the routes (or services) it happened on over the last week
     * as shares, the release it was first seen in, and its latest occurrence's route, trace and (hashed) user.
     *
     * @param  Issue  $issue
     * @return array{where: list<array{label: string, value: int}>, firstRelease: string|null, latest: array{route: string|null, traceId: string|null, user: string|null, at: string}|null}
     */
    public function context(Issue $issue): array
    {
        $recent = TelemetryEvent::query()->where('issue_id', $issue->id)->where('occurred_at', '>=', CarbonImmutable::now('UTC')->subDays(7))
            ->latest('occurred_at')->limit(500)->get(['route', 'service']);
        $where = $recent->map(fn (TelemetryEvent $event): string => $event->route ?? $event->service ?? '')->filter()->countBy()->sortDesc()->take(5);
        $total = max(1, $recent->count());
        $first = TelemetryEvent::query()->with('release')->where('issue_id', $issue->id)->whereNotNull('release_id')->oldest('occurred_at')->first();
        $latest = TelemetryEvent::query()->where('issue_id', $issue->id)->latest('occurred_at')->latest('id')->first();
        $user = $latest?->attributes['user.id'] ?? null;

        return [
            'where' => array_values($where->map(fn (int $count, string $label): array => ['label' => $label, 'value' => (int) round($count / $total * 100)])->all()),
            'firstRelease' => $first?->release?->version,
            'latest' => $latest === null ? null : [
                'route' => $latest->route, 'traceId' => $latest->trace_id, 'user' => is_string($user) ? $user : null, 'at' => $latest->occurred_at->toIso8601String(),
            ],
        ];
    }

    /**
     * Count timestamps into hourly buckets ending now, oldest first.
     *
     * @param  Collection<int, mixed>  $times
     * @param  CarbonImmutable  $now
     * @param  int  $hours
     * @return list<int>
     */
    private function hourly(Collection $times, CarbonImmutable $now, int $hours): array
    {
        $buckets = array_fill(0, $hours, 0);
        foreach ($times as $at) {
            $ago = (int) floor(CarbonImmutable::parse($at)->diffInSeconds($now, true) / 3600);
            if ($ago < $hours) {
                $buckets[$hours - 1 - $ago]++;
            }
        }

        return array_values($buckets);
    }
}
