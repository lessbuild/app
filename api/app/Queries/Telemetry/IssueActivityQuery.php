<?php

declare(strict_types=1);

namespace App\Queries\Telemetry;

use App\Enums\IssueStatus;
use App\Models\Issue;
use App\Models\TelemetryEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class IssueActivityQuery
{
    /**
     * Summarise matching issues: open and new counts, event totals over the last day and the day before, and affected
     * people. Status is intentionally ignored so these counts stay useful while filtering issue state.
     *
     * @param  Builder<Issue>  $matchingIssues  The issues matching search, severity, ownership and environment filters; status is intentionally ignored.
     * @param  string|null  $environmentId  Restrict event counts to this environment as well.
     * @return array{open: int, newToday: int, events: int, eventsBefore: int, hourly: list<int>, users: int}
     */
    public function stats(Builder $matchingIssues, ?string $environmentId = null): array
    {
        $now = CarbonImmutable::now('UTC');
        $issues = clone $matchingIssues;
        $open = (clone $issues)->where('status', IssueStatus::Open);
        $events = TelemetryEvent::query()->whereIn('issue_id', (clone $issues)->select('issues.id'))
            ->when($environmentId !== null, fn (Builder $query): Builder => $query->where('environment_id', $environmentId))
            ->where('occurred_at', '>=', $now->subHours(48));

        $selects = [
            'COUNT(CASE WHEN occurred_at >= ? THEN 1 END) AS events',
            'COUNT(CASE WHEN occurred_at < ? THEN 1 END) AS events_before',
        ];
        $bindings = [$now->subDay(), $now->subDay()];
        for ($index = 0; $index < 24; $index++) {
            $from = $now->subHours(24 - $index);
            $until = $from->addHour();
            $selects[] = "COUNT(CASE WHEN occurred_at >= ? AND occurred_at < ? THEN 1 END) AS hour_{$index}";
            $bindings[] = $from;
            $bindings[] = $until;
        }
        $counts = $events->selectRaw(implode(', ', $selects), $bindings)->first();

        return [
            'open' => $open->count(),
            'newToday' => (clone $issues)->where('first_seen_at', '>=', $now->startOfDay())->count(),
            'events' => (int) ($counts?->events ?? 0),
            'eventsBefore' => (int) ($counts?->events_before ?? 0),
            'hourly' => array_map(fn (int $index): int => (int) ($counts?->{"hour_{$index}"} ?? 0), range(0, 23)),
            'users' => (int) $open->sum('affected_users'),
        ];
    }

    /**
     * Add recent exception counts and sort high-impact issues first. The default order favors active volume; callers
     * can still choose newest-first from the issues page.
     *
     * @param  Builder<Issue>  $issues
     * @param  array<string, mixed>  $filters
     * @return Builder<Issue>
     */
    public function prioritize(Builder $issues, array $filters): Builder
    {
        $now = CarbonImmutable::now('UTC');
        $current = TelemetryEvent::query()->selectRaw('COUNT(*)')->whereColumn('issue_id', 'issues.id')
            ->where('type', 'exception')->where('occurred_at', '>=', $now->subHour())
            ->when(isset($filters['environment']), fn (Builder $query): Builder => $query->where('environment_id', $filters['environment']));
        $previous = TelemetryEvent::query()->selectRaw('COUNT(*)')->whereColumn('issue_id', 'issues.id')
            ->where('type', 'exception')->where('occurred_at', '>=', $now->subHours(2))->where('occurred_at', '<', $now->subHour())
            ->when(isset($filters['environment']), fn (Builder $query): Builder => $query->where('environment_id', $filters['environment']));

        $issues->addSelect('issues.*')->selectSub($current, 'events_last_hour')->selectSub($previous, 'events_previous_hour');
        if (($filters['sort'] ?? 'impact') === 'newest') {
            return $issues->orderByDesc('last_seen_at')->orderByDesc('id');
        }

        return $issues->orderByDesc('events_last_hour')
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'error' THEN 1 WHEN 'warning' THEN 2 WHEN 'info' THEN 3 ELSE 4 END")
            ->orderByDesc('affected_users')->orderByDesc('last_seen_at')->orderByDesc('id');
    }

    /**
     * Count each issue's occurrences in each of the last hours, oldest first.
     *
     * @param  list<int>  $issueIds
     * @param  int  $hours
     * @param  string|null  $environmentId
     * @return array<int, list<int>>
     */
    public function trends(array $issueIds, int $hours = 12, ?string $environmentId = null): array
    {
        if ($issueIds === []) {
            return [];
        }
        $now = CarbonImmutable::now('UTC');
        $selects = ['issue_id'];
        $bindings = [];
        for ($index = 0; $index < $hours; $index++) {
            $from = $now->subHours($hours - $index);
            $until = $from->addHour();
            $selects[] = "COUNT(CASE WHEN occurred_at >= ? AND occurred_at < ? THEN 1 END) AS hour_{$index}";
            $bindings[] = $from;
            $bindings[] = $until;
        }
        $events = TelemetryEvent::query()->whereIn('issue_id', $issueIds)->where('type', 'exception')
            ->when($environmentId !== null, fn (Builder $query): Builder => $query->where('environment_id', $environmentId))
            ->where('occurred_at', '>=', $now->subHours($hours))
            ->selectRaw(implode(', ', $selects), $bindings)->groupBy('issue_id')->get()->keyBy('issue_id');

        $trends = [];
        foreach ($issueIds as $id) {
            $row = $events->get($id);
            $trends[$id] = array_map(fn (int $index): int => (int) ($row?->{"hour_{$index}"} ?? 0), range(0, $hours - 1));
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
}
