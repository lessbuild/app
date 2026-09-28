<?php

declare(strict_types=1);

namespace App\Queries\Telemetry;

use App\Models\Project;
use App\Models\TelemetryEvent;
use App\Support\Telemetry\EventTextSearch;
use App\Support\Telemetry\EventTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class EventsQuery
{
    /**
     * The project's events matching the event browser's filters (environment, release, type, severity, service, trace,
     * status, duration and text) inside the time window. A custom window's end is exclusive, since its minutes come from
     * a form.
     *
     * @param  Project  $project
     * @param  array<string, mixed>  $filters
     * @param  array{CarbonImmutable|null, CarbonImmutable|null}  $window
     * @return Builder<TelemetryEvent>
     */
    public function handle(Project $project, array $filters, array $window): Builder
    {
        $query = TelemetryEvent::query()->whereIn('environment_id', $project->environments()->select('id'));

        foreach (['environment' => 'environment_id', 'release' => 'release_id', 'type' => 'type', 'severity' => 'severity', 'service' => 'service', 'trace' => 'trace_id', 'status' => 'status_code'] as $filter => $column) {
            if (isset($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }

        if (isset($filters['has_trace'])) {
            if ($filters['has_trace'] === 'yes') {
                $query->whereNotNull('trace_id')->where('trace_id', '!=', '');
            } else {
                $query->where(fn (Builder $trace) => $trace->whereNull('trace_id')->orWhere('trace_id', ''));
            }
        }

        if (isset($filters['min_duration'])) {
            $query->where('duration_ms', '>=', (float) $filters['min_duration']);
        }
        if (isset($filters['q'])) {
            EventTextSearch::apply($query, $filters['q']);
        }

        [$from, $to] = $window;
        if ($from !== null) {
            $query->where('occurred_at', '>=', EventTime::boundary($from));
        }
        if ($to !== null) {
            $query->where('occurred_at', $filters['range'] === 'custom' ? '<' : '<=', $to->format($filters['range'] === 'custom' ? 'Y-m-d H:i:s' : 'Y-m-d H:i:s.u'));
        }

        return $query;
    }

    /**
     * Orders events newest first, oldest first, or slowest first (events without a duration last), with ties broken by
     * the precise timestamp and ID.
     *
     * @param  Builder<TelemetryEvent>  $query
     * @param  string  $sort
     * @return Builder<TelemetryEvent>
     */
    public function ordered(Builder $query, string $sort): Builder
    {
        if ($sort === 'slowest') {
            $query->orderByRaw('CASE WHEN duration_ms IS NULL THEN 1 ELSE 0 END')->orderByDesc('duration_ms');
        }
        $direction = $sort === 'oldest' ? 'asc' : 'desc';

        return $query->orderBy('occurred_at', $direction)->orderBy('timestamp_unix_nano', $direction)->orderBy('id', $direction);
    }

    /**
     * The time window for a range: all time, a custom range entered in UTC, or the last 15 minutes, hour, day, 7 or 30
     * days.
     *
     * @param  array<string, mixed>  $filters
     * @return array{CarbonImmutable|null, CarbonImmutable|null}
     */
    public function window(array $filters): array
    {
        if ($filters['range'] === 'all') {
            return [null, null];
        }
        if ($filters['range'] === 'custom') {
            return [CarbonImmutable::parse($filters['from'].':00Z'), CarbonImmutable::parse($filters['to'].':00Z')];
        }
        $until = CarbonImmutable::now('UTC');
        $minutes = match ($filters['range']) {
            '15m' => 15,
            '1h' => 60,
            '7d' => 10_080,
            '30d' => 43_200,
            default => 1_440,
        };

        return [$until->subMinutes($minutes), $until];
    }
}
