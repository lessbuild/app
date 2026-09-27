<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Models\Monitor;
use App\Models\MonitorCheck;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Thirty days of completed checks per monitor, one bar per UTC day. Only checks of the monitor's current
 * configuration count, so changing what a monitor checks starts its history again.
 *
 * @phpstan-type Day array{date: string, label: string, state: string, passed: int, failed: int, unknown: int, measured: int, uptime: ?float, summary: string}
 * @phpstan-type History array{uptime: ?float, measured: int, passed: int, failed: int, unknown: int, days: list<Day>}
 */
final class UptimeHistory
{
    public const WINDOW_DAYS = 30;

    /**
     * @param  iterable<Monitor>  $monitors
     * @return array<int, History> keyed by monitor ID
     */
    public function forMonitors(iterable $monitors, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now('UTC'))->utc();
        $start = $now->startOfDay()->subDays(self::WINDOW_DAYS - 1);
        $revisions = [];
        foreach ($monitors as $monitor) {
            $revisions[$monitor->id] = $monitor->config_revision;
        }
        if ($revisions === []) {
            return [];
        }

        $checks = MonitorCheck::query()
            ->whereIn('monitor_id', array_keys($revisions))
            ->where('status', 'completed')
            ->whereIn('outcome', ['up', 'down', 'unknown'])
            ->whereBetween('scheduled_at', [$start, $now])
            ->where(function (Builder $query) use ($revisions): void {
                foreach ($revisions as $monitorId => $revision) {
                    $query->orWhere(fn (Builder $query) => $query->where('monitor_id', $monitorId)->where('config_revision', $revision));
                }
            })
            ->toBase()
            ->get(['monitor_id', 'outcome', 'scheduled_at']);

        /** @var array<int, array<string, array{up: int, down: int, unknown: int}>> $counts */
        $counts = [];
        foreach ($checks as $check) {
            $day = CarbonImmutable::parse((string) $check->scheduled_at, 'UTC')->toDateString();
            $counts[(int) $check->monitor_id][$day] ??= ['up' => 0, 'down' => 0, 'unknown' => 0];
            $outcome = match ((string) $check->outcome) {
                'up' => 'up',
                'down' => 'down',
                default => 'unknown',
            };
            $counts[(int) $check->monitor_id][$day][$outcome]++;
        }

        $histories = [];
        foreach (array_keys($revisions) as $monitorId) {
            $histories[$monitorId] = $this->summarise($counts[$monitorId] ?? [], $start);
        }

        return $histories;
    }

    /**
     * @param  array<string, array{up: int, down: int, unknown: int}>  $counts
     * @return History
     */
    private function summarise(array $counts, CarbonImmutable $start): array
    {
        $days = [];
        $totals = ['up' => 0, 'down' => 0, 'unknown' => 0];
        for ($offset = 0; $offset < self::WINDOW_DAYS; $offset++) {
            $day = $start->addDays($offset);
            $count = $counts[$day->toDateString()] ?? ['up' => 0, 'down' => 0, 'unknown' => 0];
            foreach ($count as $outcome => $n) {
                $totals[$outcome] += $n;
            }
            $measured = $count['up'] + $count['down'];
            $days[] = [
                'date' => $day->toDateString(),
                'label' => $day->format('M j'),
                'state' => match (true) {
                    $count['down'] > 0 => 'outage',
                    $count['unknown'] > 0 => 'degraded',
                    $measured > 0 => 'operational',
                    default => 'no_data',
                },
                'passed' => $count['up'],
                'failed' => $count['down'],
                'unknown' => $count['unknown'],
                'measured' => $measured,
                'uptime' => $measured > 0 ? round($count['up'] / $measured * 100, 2) : null,
                'summary' => $this->summary($count),
            ];
        }
        $measured = $totals['up'] + $totals['down'];

        return [
            'uptime' => $measured > 0 ? round($totals['up'] / $measured * 100, 2) : null,
            'measured' => $measured,
            'passed' => $totals['up'],
            'failed' => $totals['down'],
            'unknown' => $totals['unknown'],
            'days' => $days,
        ];
    }

    /** @param array{up: int, down: int, unknown: int} $count */
    private function summary(array $count): string
    {
        if (array_sum($count) === 0) {
            return __('No completed checks');
        }
        $parts = [];
        if ($count['up'] + $count['down'] > 0) {
            $parts[] = __(':count observed', ['count' => $count['up'] + $count['down']]);
        }
        if ($count['down'] > 0) {
            $parts[] = __(':count failed', ['count' => $count['down']]);
        }
        if ($count['unknown'] > 0) {
            $parts[] = __(':count unknown', ['count' => $count['unknown']]);
        }

        return implode(' · ', $parts);
    }
}
