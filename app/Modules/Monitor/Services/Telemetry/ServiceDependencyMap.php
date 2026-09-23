<?php

namespace App\Modules\Monitor\Services\Telemetry;

use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\TelemetryEvent;
use App\Modules\Monitor\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class ServiceDependencyMap
{
    public const RANGES = [
        '1h' => 'Last hour',
        '24h' => 'Last 24 hours',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
    ];

    private const MAX_EVENTS = 20_000;

    /**
     * @return array{
     *     from: CarbonImmutable,
     *     until: CarbonImmutable,
     *     records: int,
     *     traces: int,
     *     services: list<array{name: string, span_count: int, error_count: int, error_rate: float, average_duration: float|null, last_seen: CarbonImmutable|null}>,
     *     edges: list<array{source: string, target: string, calls: int, trace_count: int, error_count: int, error_rate: float, average_duration: float|null, max_duration: float|null, last_seen: CarbonImmutable|null}>,
     *     service_count: int,
     *     dependency_count: int,
     *     truncated: bool
     * }
     */
    public function forWorkspace(Workspace $workspace, string $range, ?int $environmentId = null): array
    {
        [$minutes] = match ($range) {
            '1h' => [60],
            '7d' => [10_080],
            '30d' => [43_200],
            default => [1_440],
        };
        $until = CarbonImmutable::now('UTC');
        $from = $until->subMinutes($minutes);
        $environmentIds = Environment::forWorkspace($workspace)->select('id');
        if ($environmentId !== null) {
            $environmentIds->whereKey($environmentId);
        }

        $events = TelemetryEvent::query()
            ->whereIn('environment_id', $environmentIds)
            ->whereNotNull('trace_id')
            ->whereNotNull('span_id')
            ->where('occurred_at', '>=', $this->boundary($from))
            ->where('occurred_at', '<=', $this->boundary($until))
            ->where(function (Builder $query): void {
                $query->where('payload->signal', 'traces')
                    ->orWhereIn('type', ['request', 'query', 'job']);
            })
            ->select([
                'id', 'trace_id', 'span_id', 'parent_span_id', 'type', 'severity',
                'service', 'status_code', 'duration_ms', 'occurred_at', 'payload',
            ])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->limit(self::MAX_EVENTS + 1)
            ->get();
        $truncated = $events->count() > self::MAX_EVENTS;
        $events = $events->take(self::MAX_EVENTS)->values();
        $spanServices = [];
        $services = [];
        $traceIds = [];

        foreach ($events as $event) {
            $service = $this->serviceName($event->service);
            $key = $this->spanKey($event->trace_id, $event->span_id);
            if ($key !== null && ! isset($spanServices[$key])) {
                $spanServices[$key] = $service;
            }
            $services[$service] ??= $this->emptyService($service);
            $services[$service]['span_count']++;
            if ($this->isError($event)) {
                $services[$service]['error_count']++;
            }
            $duration = $this->validDuration($event->duration_ms);
            if ($duration !== null) {
                $services[$service]['duration_total'] += $duration;
                $services[$service]['duration_count']++;
            }
            $services[$service]['last_seen'] = $this->latest($services[$service]['last_seen'], $event->occurred_at);
            if (filled($event->trace_id)) {
                $traceIds[(string) $event->trace_id] = true;
            }
        }

        $edges = [];
        foreach ($events as $event) {
            $childKey = $this->spanKey($event->trace_id, $event->span_id);
            $parentKey = $this->spanKey($event->trace_id, $event->parent_span_id);
            if ($childKey === null || $parentKey === null) {
                continue;
            }
            $target = $spanServices[$childKey] ?? null;
            $source = $spanServices[$parentKey] ?? null;
            if ($source === null || $target === null || $source === $target) {
                continue;
            }

            $key = $source.'\x1F'.$target;
            $edges[$key] ??= $this->emptyEdge($source, $target);
            $edges[$key]['calls']++;
            if ($this->isError($event)) {
                $edges[$key]['error_count']++;
            }
            $duration = $this->validDuration($event->duration_ms);
            if ($duration !== null) {
                $edges[$key]['duration_total'] += $duration;
                $edges[$key]['duration_count']++;
                $edges[$key]['max_duration'] = max($edges[$key]['max_duration'] ?? 0.0, $duration);
            }
            if (filled($event->trace_id)) {
                $edges[$key]['trace_ids'][(string) $event->trace_id] = true;
            }
            $edges[$key]['last_seen'] = $this->latest($edges[$key]['last_seen'], $event->occurred_at);
        }

        $serviceRows = collect($services)
            ->map(fn (array $service): array => [
                'name' => $service['name'],
                'span_count' => $service['span_count'],
                'error_count' => $service['error_count'],
                'error_rate' => $this->percentage($service['error_count'], $service['span_count']),
                'average_duration' => $service['duration_count'] > 0
                    ? round($service['duration_total'] / $service['duration_count'], 3) : null,
                'last_seen' => $service['last_seen'],
            ])
            ->sortByDesc(fn (array $service): array => [$service['span_count'], $service['name']])
            ->values()
            ->all();
        $edgeRows = collect($edges)
            ->map(fn (array $edge): array => [
                'source' => $edge['source'],
                'target' => $edge['target'],
                'calls' => $edge['calls'],
                'trace_count' => count($edge['trace_ids']),
                'error_count' => $edge['error_count'],
                'error_rate' => $this->percentage($edge['error_count'], $edge['calls']),
                'average_duration' => $edge['duration_count'] > 0
                    ? round($edge['duration_total'] / $edge['duration_count'], 3) : null,
                'max_duration' => $edge['max_duration'] !== null ? round($edge['max_duration'], 3) : null,
                'last_seen' => $edge['last_seen'],
            ])
            ->sortByDesc(fn (array $edge): array => [$edge['calls'], $edge['error_rate'], $edge['source'], $edge['target']])
            ->values()
            ->all();

        return [
            'from' => $from,
            'until' => $until,
            'records' => $events->count(),
            'traces' => count($traceIds),
            'services' => $serviceRows,
            'edges' => $edgeRows,
            'service_count' => count($serviceRows),
            'dependency_count' => count($edgeRows),
            'truncated' => $truncated,
        ];
    }

    /** @return array{name: string, span_count: int, error_count: int, duration_total: float, duration_count: int, last_seen: CarbonImmutable|null} */
    private function emptyService(string $name): array
    {
        return ['name' => $name, 'span_count' => 0, 'error_count' => 0, 'duration_total' => 0.0, 'duration_count' => 0, 'last_seen' => null];
    }

    /** @return array{source: string, target: string, calls: int, error_count: int, duration_total: float, duration_count: int, max_duration: float|null, trace_ids: array<string, bool>, last_seen: CarbonImmutable|null} */
    private function emptyEdge(string $source, string $target): array
    {
        return ['source' => $source, 'target' => $target, 'calls' => 0, 'error_count' => 0, 'duration_total' => 0.0, 'duration_count' => 0, 'max_duration' => null, 'trace_ids' => [], 'last_seen' => null];
    }

    private function serviceName(?string $service): string
    {
        return filled($service) ? $service : 'Unspecified service';
    }

    private function spanKey(?string $traceId, ?string $spanId): ?string
    {
        return filled($traceId) && filled($spanId) ? $traceId.'|'.$spanId : null;
    }

    private function isError(TelemetryEvent $event): bool
    {
        return in_array($event->severity, ['error', 'critical'], true) || ($event->status_code !== null && $event->status_code >= 500);
    }

    private function validDuration(mixed $duration): ?float
    {
        return is_numeric($duration) && is_finite((float) $duration) && (float) $duration >= 0 ? (float) $duration : null;
    }

    private function percentage(int $part, int $whole): float
    {
        return $whole > 0 ? round($part / $whole * 100, 2) : 0.0;
    }

    private function latest(?CarbonImmutable $current, mixed $candidate): ?CarbonImmutable
    {
        if ($candidate === null) {
            return $current;
        }

        $candidate = $candidate instanceof CarbonImmutable ? $candidate : CarbonImmutable::parse($candidate, 'UTC');

        return $current === null || $candidate->gt($current) ? $candidate : $current;
    }

    private function boundary(CarbonImmutable $time): string
    {
        return $time->format($time->micro === 0 ? 'Y-m-d H:i:s' : 'Y-m-d H:i:s.u');
    }
}
