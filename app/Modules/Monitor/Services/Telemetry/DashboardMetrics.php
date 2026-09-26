<?php

namespace App\Modules\Monitor\Services\Telemetry;

use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\TelemetryEvent;
use App\Modules\Monitor\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use stdClass;

final class DashboardMetrics
{
    public const RANGES = [
        '24h' => 'Last 24 hours',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
    ];

    /**
     * @return array{
     *     from: CarbonImmutable, until: CarbonImmutable, previousFrom: CarbonImmutable, bucketMinutes: int,
     *     eventCount: int, requestCount: int, timedRequestCount: int, failedRequestCount: int,
     *     averageDuration: float|null, requestErrorRate: float|null,
     *     previous: array{eventCount: int, requestCount: int, timedRequestCount: int, failedRequestCount: int, averageDuration: float|null, requestErrorRate: float|null},
     *     changes: array{events: float|null, duration: float|null, errorRate: float|null},
     *     eventBreakdown: array<string, int>,
     *     trend: list<array{from: CarbonImmutable, until: CarbonImmutable, label: string, eventCount: int, requestCount: int, timedRequestCount: int, failedRequestCount: int, averageDuration: float|null, requestErrorRate: float|null}>,
     *     chartMaxDuration: float, chartMaxRequests: int
     * }
     */
    public function forWorkspace(Workspace $workspace, string $range, ?Authenticatable $principal = null): array
    {
        [$minutes, $bucketMinutes] = match ($range) {
            '7d' => [10_080, 1_440],
            '30d' => [43_200, 2_880],
            default => [1_440, 120],
        };
        $until = CarbonImmutable::now('UTC');
        $from = $until->subMinutes($minutes);
        $previousFrom = $from->subMinutes($minutes);
        $bucketCount = intdiv($minutes, $bucketMinutes);
        $bucketCase = 'CASE WHEN occurred_at < ? THEN -1';
        $bindings = [$this->boundary($from)];

        for ($index = 0; $index < $bucketCount - 1; $index++) {
            $bucketCase .= " WHEN occurred_at < ? THEN {$index}";
            $bindings[] = $this->boundary($from->addMinutes(($index + 1) * $bucketMinutes));
        }
        $bucketCase .= ' ELSE '.($bucketCount - 1).' END';

        $rows = $this->events($workspace, $previousFrom, $until, $principal)->toBase()
            ->selectRaw($bucketCase.' AS time_bucket', $bindings)
            ->selectRaw("CASE type WHEN 'request' THEN 'request' WHEN 'query' THEN 'query' WHEN 'job' THEN 'job' WHEN 'exception' THEN 'exception' WHEN 'log' THEN 'log' WHEN 'metric' THEN 'metric' ELSE 'other' END AS event_type")
            ->selectRaw('COUNT(*) AS event_count')
            ->selectRaw("COUNT(CASE WHEN type = 'request' THEN 1 END) AS request_count")
            ->selectRaw("COUNT(CASE WHEN type = 'request' AND duration_ms >= 0 THEN 1 END) AS timed_request_count")
            ->selectRaw("SUM(CASE WHEN type = 'request' AND duration_ms >= 0 THEN duration_ms ELSE 0 END) AS total_request_duration")
            ->selectRaw("COUNT(CASE WHEN type = 'request' AND (status_code BETWEEN 500 AND 599 OR severity IN ('error', 'critical')) THEN 1 END) AS failed_request_count")
            ->groupBy('time_bucket', 'event_type')
            ->get();
        $currentRows = $rows->filter(fn (stdClass $row): bool => (int) $row->time_bucket >= 0);
        $current = $this->summarize($currentRows);
        $previous = $this->summarize($rows->filter(fn (stdClass $row): bool => (int) $row->time_bucket === -1));
        $eventBreakdown = array_fill_keys(['request', 'query', 'job', 'exception', 'log', 'metric', 'other'], 0);
        foreach ($currentRows as $row) {
            $eventBreakdown[$row->event_type] += (int) $row->event_count;
        }

        $byBucket = $currentRows->groupBy('time_bucket');
        $trend = [];
        for ($index = 0; $index < $bucketCount; $index++) {
            $start = $from->addMinutes($index * $bucketMinutes);
            $trend[] = [
                'from' => $start,
                'until' => $start->addMinutes($bucketMinutes),
                'label' => $start->format($range === '24h' ? 'H:i' : 'd M'),
                ...$this->summarize($byBucket->get($index, collect())),
            ];
        }

        return [
            'from' => $from,
            'until' => $until,
            'previousFrom' => $previousFrom,
            'bucketMinutes' => $bucketMinutes,
            ...$current,
            'previous' => $previous,
            'changes' => [
                'events' => $this->percentageChange($current['eventCount'], $previous['eventCount']),
                'duration' => $this->percentageChange($current['averageDuration'], $previous['averageDuration']),
                'errorRate' => $current['requestErrorRate'] !== null && $previous['requestErrorRate'] !== null
                    ? round($current['requestErrorRate'] - $previous['requestErrorRate'], 2)
                    : null,
            ],
            'eventBreakdown' => $eventBreakdown,
            'trend' => $trend,
            'chartMaxDuration' => (float) (collect($trend)->max('averageDuration') ?? 0),
            'chartMaxRequests' => (int) collect($trend)->max('requestCount'),
        ];
    }

    /** @return Builder<TelemetryEvent> */
    public function events(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $until, ?Authenticatable $principal = null): Builder
    {
        return TelemetryEvent::query()
            ->whereIn('environment_id', Environment::forWorkspace($workspace)->when($principal !== null, fn ($query) => $query->visibleTo($principal, $workspace))->select('id'))
            ->where('occurred_at', '>=', $this->boundary($from))
            ->where('occurred_at', '<=', $until->format('Y-m-d H:i:s.u'));
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     * @return array{eventCount: int, requestCount: int, timedRequestCount: int, failedRequestCount: int, averageDuration: float|null, requestErrorRate: float|null}
     */
    private function summarize(Collection $rows): array
    {
        $requestCount = (int) $rows->sum('request_count');
        $timedRequestCount = (int) $rows->sum('timed_request_count');
        $failedRequestCount = (int) $rows->sum('failed_request_count');

        return [
            'eventCount' => (int) $rows->sum('event_count'),
            'requestCount' => $requestCount,
            'timedRequestCount' => $timedRequestCount,
            'failedRequestCount' => $failedRequestCount,
            'averageDuration' => $timedRequestCount > 0 ? (float) $rows->sum('total_request_duration') / $timedRequestCount : null,
            'requestErrorRate' => $requestCount > 0 ? $failedRequestCount / $requestCount * 100 : null,
        ];
    }

    private function percentageChange(?float $current, ?float $previous): ?float
    {
        return $current !== null && $previous !== null && $previous > 0
            ? round(($current - $previous) / $previous * 100, 1)
            : null;
    }

    /** Keep legacy second-only SQLite timestamps on the correct side of each boundary. */
    private function boundary(CarbonImmutable $time): string
    {
        return $time->format($time->micro === 0 ? 'Y-m-d H:i:s' : 'Y-m-d H:i:s.u');
    }
}
