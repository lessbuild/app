<?php

declare(strict_types=1);

namespace App\Queries\Telemetry;

use App\Models\Account;
use App\Models\Environment;
use App\Models\TelemetryEvent;
use App\Support\Telemetry\EventTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * An account's telemetry over a range: event volume, request duration and error rate, compared with the
 * range before, plus a trend in equal buckets and the mix of event types.
 *
 * @phpstan-type Totals array{eventCount: int, requestCount: int, timedRequestCount: int, failedRequestCount: int, averageDuration: float|null, requestErrorRate: float|null}
 * @phpstan-type Summary array{from: CarbonImmutable, until: CarbonImmutable, eventCount: int, requestCount: int, timedRequestCount: int, failedRequestCount: int, averageDuration: float|null, requestErrorRate: float|null, previous: Totals, changes: array{events: float|null, duration: float|null, errorRate: float|null}, eventBreakdown: array<string, int>, trend: list<array{from: CarbonImmutable, label: string, eventCount: int, requestCount: int, timedRequestCount: int, failedRequestCount: int, averageDuration: float|null, requestErrorRate: float|null}>}
 */
final class TelemetrySummaryQuery
{
    public const RANGES = ['24h' => 'Last 24 hours', '7d' => 'Last 7 days', '30d' => 'Last 30 days'];

    public const TYPES = ['request', 'query', 'job', 'exception', 'log', 'metric', 'other'];

    /**
     * The account's telemetry for a range compared with the range before: totals, request duration and error rate, a
     * breakdown by event type, and a trend split into buckets. Everything is counted in one grouped query.
     *
     * @param  Account  $account
     * @param  string  $range
     * @param  CarbonImmutable|null  $now
     * @return Summary
     */
    public function handle(Account $account, string $range, ?CarbonImmutable $now = null): array
    {
        [$minutes, $bucketMinutes] = match ($range) {
            '7d' => [10_080, 1_440],
            '30d' => [43_200, 2_880],
            default => [1_440, 120],
        };
        $until = ($now ?? CarbonImmutable::now('UTC'))->utc();
        $from = $until->subMinutes($minutes);
        $buckets = intdiv($minutes, $bucketMinutes);
        // Minutes since the start of the range, per database; rows before it belong to the previous range.
        $offset = DB::getDriverName() === 'pgsql'
            ? 'FLOOR(EXTRACT(EPOCH FROM (occurred_at - CAST(? AS timestamp))) / 60 / ?)'
            : 'CAST((julianday(occurred_at) - julianday(?)) * 1440 / ? AS INTEGER)';

        $rows = $this->events($account, $from->subMinutes($minutes), $until)->toBase()
            ->selectRaw('CASE WHEN occurred_at < ? THEN -1 ELSE '.$offset.' END AS time_bucket', [EventTime::boundary($from), EventTime::boundary($from), $bucketMinutes])
            ->selectRaw("CASE WHEN type IN ('request', 'query', 'job', 'exception', 'log', 'metric') THEN type ELSE 'other' END AS event_type")
            ->selectRaw('COUNT(*) AS event_count')
            ->selectRaw("COUNT(CASE WHEN type = 'request' THEN 1 END) AS request_count")
            ->selectRaw("COUNT(CASE WHEN type = 'request' AND duration_ms >= 0 THEN 1 END) AS timed_request_count")
            ->selectRaw("SUM(CASE WHEN type = 'request' AND duration_ms >= 0 THEN duration_ms ELSE 0 END) AS total_request_duration")
            ->selectRaw("COUNT(CASE WHEN type = 'request' AND (status_code BETWEEN 500 AND 599 OR severity IN ('error', 'critical')) THEN 1 END) AS failed_request_count")
            ->groupBy('time_bucket', 'event_type')
            ->get()
            ->map(function (stdClass $row) use ($buckets): stdClass {
                $row->time_bucket = min((int) $row->time_bucket, $buckets - 1);

                return $row;
            });

        $currentRows = $rows->filter(fn (stdClass $row): bool => $row->time_bucket >= 0);
        $current = $this->totals($currentRows);
        $previous = $this->totals($rows->filter(fn (stdClass $row): bool => $row->time_bucket === -1));
        $breakdown = array_fill_keys(self::TYPES, 0);
        foreach ($currentRows as $row) {
            $breakdown[(string) $row->event_type] = ($breakdown[(string) $row->event_type] ?? 0) + (int) $row->event_count;
        }
        $byBucket = $currentRows->groupBy('time_bucket');
        $trend = [];
        for ($index = 0; $index < $buckets; $index++) {
            $start = $from->addMinutes($index * $bucketMinutes);
            $trend[] = ['from' => $start, 'label' => $start->format($range === '24h' ? 'H:i' : 'd M'), ...$this->totals($byBucket->get($index, collect()))];
        }

        return [
            'from' => $from,
            'until' => $until,
            ...$current,
            'previous' => $previous,
            'changes' => [
                'events' => $this->change($current['eventCount'], $previous['eventCount']),
                'duration' => $this->change($current['averageDuration'], $previous['averageDuration']),
                'errorRate' => $current['requestErrorRate'] !== null && $previous['requestErrorRate'] !== null ? round($current['requestErrorRate'] - $previous['requestErrorRate'], 2) : null,
            ],
            'eventBreakdown' => $breakdown,
            'trend' => $trend,
        ];
    }

    /**
     * The account's events inside a window.
     *
     * @param  Account  $account
     * @param  CarbonImmutable  $from
     * @param  CarbonImmutable  $until
     * @return Builder<TelemetryEvent>
     */
    public function events(Account $account, CarbonImmutable $from, CarbonImmutable $until): Builder
    {
        return TelemetryEvent::query()
            ->whereIn('environment_id', Environment::query()->whereIn('project_id', $account->projects()->select('id'))->select('id'))
            ->where('occurred_at', '>=', EventTime::boundary($from))
            ->where('occurred_at', '<=', $until->format('Y-m-d H:i:s.u'));
    }

    /**
     * Adds up grouped rows into event and request counts, average request duration and error rate.
     *
     * @param  Collection<int, stdClass>  $rows
     * @return Totals
     */
    private function totals(Collection $rows): array
    {
        $requests = (int) $rows->sum('request_count');
        $timed = (int) $rows->sum('timed_request_count');
        $failed = (int) $rows->sum('failed_request_count');

        return [
            'eventCount' => (int) $rows->sum('event_count'),
            'requestCount' => $requests,
            'timedRequestCount' => $timed,
            'failedRequestCount' => $failed,
            'averageDuration' => $timed > 0 ? (float) $rows->sum('total_request_duration') / $timed : null,
            'requestErrorRate' => $requests > 0 ? $failed / $requests * 100 : null,
        ];
    }

    /**
     * The change from the previous range as a percentage, or null when either side is missing or the previous one is
     * zero.
     *
     * @param  int|float|null  $current
     * @param  int|float|null  $previous
     * @return float|null
     */
    private function change(int|float|null $current, int|float|null $previous): ?float
    {
        return $current !== null && $previous !== null && $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : null;
    }
}
