<?php

namespace App\Modules\Monitor\Services\Telemetry;

use App\Modules\Monitor\Models\TelemetryEvent;
use App\Modules\Monitor\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class SearchEvents
{
    /**
     * @param  array<string, mixed>  $filters
     * @param  array{CarbonImmutable|null, CarbonImmutable|null}  $window
     * @return Builder<TelemetryEvent>
     */
    public function query(Workspace $workspace, array $filters, array $window): Builder
    {
        $query = TelemetryEvent::forWorkspace($workspace);
        if (isset($filters['application'])) {
            $query->whereHas('environment', fn (Builder $environment) => $environment->where('application_id', $filters['application']));
        }

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
            $this->containsText($query, $filters['q']);
        }

        [$from, $to] = $window;
        if ($from !== null) {
            $query->where('occurred_at', '>=', $from->format($from->micro === 0 ? 'Y-m-d H:i:s' : 'Y-m-d H:i:s.u'));
        }
        if ($to !== null) {
            $query->where('occurred_at', $filters['range'] === 'custom' ? '<' : '<=', $to->format($filters['range'] === 'custom' ? 'Y-m-d H:i:s' : 'Y-m-d H:i:s.u'));
        }

        return $query;
    }

    /**
     * @param  Builder<TelemetryEvent>  $query
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

    /** @param Builder<TelemetryEvent> $query */
    private function containsText(Builder $query, string $text): void
    {
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text).'%';
        $query->where(function (Builder $search) use ($pattern): void {
            foreach ([
                'name', 'route', 'service', 'trace_id', 'span_id',
                'payload->message', 'payload->body', 'payload->record->body->stringValue',
                'payload->_beacon->indexed_fields->name',
            ] as $column) {
                $wrapped = $search->getQuery()->getGrammar()->wrap($column);
                $search->orWhereRaw($wrapped." LIKE ? ESCAPE '!'", [$pattern]);
            }
        });
    }
}
