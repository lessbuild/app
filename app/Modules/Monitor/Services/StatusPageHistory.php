<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\MonitorCheck;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class StatusPageHistory
{
    public const WINDOW_DAYS = 30;

    /**
     * @param  Collection<int, Monitor>  $monitors
     * @return Collection<int, array{uptime: ?float, measured: int, passed: int, failed: int, unknown: int, days: Collection<int, array{date: string, label: string, state: string, passed: int, failed: int, unknown: int, measured: int, uptime: ?float, summary: string}>}>
     */
    public function forMonitors(Collection $monitors, ?CarbonImmutable $now = null): Collection
    {
        $now = ($now ?? CarbonImmutable::now('UTC'))->utc();
        $start = $now->startOfDay()->subDays(self::WINDOW_DAYS - 1);
        $monitors = $monitors->filter(fn (mixed $monitor): bool => $monitor instanceof Monitor)->values();

        if ($monitors->isEmpty()) {
            return collect();
        }

        $revisions = $monitors->mapWithKeys(fn (Monitor $monitor): array => [
            $monitor->getKey() => (int) $monitor->config_revision,
        ]);
        $checks = MonitorCheck::query()
            ->whereIn('monitor_id', $revisions->keys()->all())
            ->where('status', 'completed')
            ->whereIn('outcome', ['up', 'down', 'unknown'])
            ->whereBetween('scheduled_at', [$start, $now])
            ->where(function (Builder $query) use ($revisions): void {
                foreach ($revisions as $monitorId => $revision) {
                    $query->orWhere(function (Builder $query) use ($monitorId, $revision): void {
                        $query->where('monitor_id', $monitorId)->where('config_revision', $revision);
                    });
                }
            })
            ->get(['monitor_id', 'config_revision', 'outcome', 'scheduled_at']);
        $checksByMonitor = $checks->groupBy('monitor_id');

        return $monitors->mapWithKeys(function (Monitor $monitor) use ($checksByMonitor, $start, $revisions): array {
            $checks = $checksByMonitor->get($monitor->getKey(), collect())
                ->filter(fn (MonitorCheck $check): bool => (int) $check->config_revision === $revisions->get($monitor->getKey()))
                ->values();

            return [$monitor->getKey() => $this->summarize($checks, $start)];
        });
    }

    /**
     * @param  Collection<int, MonitorCheck>  $checks
     * @return array{uptime: ?float, measured: int, passed: int, failed: int, unknown: int, days: Collection<int, array{date: string, label: string, state: string, passed: int, failed: int, unknown: int, measured: int, uptime: ?float, summary: string}>}
     */
    private function summarize(Collection $checks, CarbonImmutable $start): array
    {
        $totals = $this->totals($checks);
        $checksByDay = $checks->groupBy(fn (MonitorCheck $check): string => CarbonImmutable::parse($check->scheduled_at)->utc()->toDateString());
        $days = collect(range(0, self::WINDOW_DAYS - 1))->map(function (int $offset) use ($checksByDay, $start): array {
            $day = $start->addDays($offset);
            $dayTotals = $this->totals($checksByDay->get($day->toDateString(), collect()));

            return [
                'date' => $day->toDateString(),
                'label' => $day->format('M j'),
                'state' => $this->state($dayTotals),
                'passed' => $dayTotals['passed'],
                'failed' => $dayTotals['failed'],
                'unknown' => $dayTotals['unknown'],
                'measured' => $dayTotals['measured'],
                'uptime' => $dayTotals['uptime'],
                'summary' => $this->summary($dayTotals),
            ];
        });

        return [
            'uptime' => $totals['uptime'],
            'measured' => $totals['measured'],
            'passed' => $totals['passed'],
            'failed' => $totals['failed'],
            'unknown' => $totals['unknown'],
            'days' => $days,
        ];
    }

    /**
     * @param  Collection<int, MonitorCheck>  $checks
     * @return array{total: int, measured: int, passed: int, failed: int, unknown: int, uptime: ?float}
     */
    private function totals(Collection $checks): array
    {
        $passed = $checks->where('outcome', 'up')->count();
        $failed = $checks->where('outcome', 'down')->count();
        $unknown = $checks->where('outcome', 'unknown')->count();
        $measured = $passed + $failed;

        return [
            'total' => $checks->count(),
            'measured' => $measured,
            'passed' => $passed,
            'failed' => $failed,
            'unknown' => $unknown,
            'uptime' => $measured > 0 ? round($passed / $measured * 100, 2) : null,
        ];
    }

    /** @param array{total: int, measured: int, passed: int, failed: int, unknown: int, uptime: ?float} $totals */
    private function state(array $totals): string
    {
        if ($totals['failed'] > 0) {
            return 'outage';
        }
        if ($totals['unknown'] > 0) {
            return 'degraded';
        }
        if ($totals['measured'] > 0) {
            return 'operational';
        }

        return 'no_data';
    }

    /** @param array{total: int, measured: int, passed: int, failed: int, unknown: int, uptime: ?float} $totals */
    private function summary(array $totals): string
    {
        if ($totals['total'] === 0) {
            return 'No completed checks';
        }

        $parts = [];
        if ($totals['measured'] > 0) {
            $parts[] = $totals['measured'].' observed';
        }
        if ($totals['failed'] > 0) {
            $parts[] = $totals['failed'].' failed';
        }
        if ($totals['unknown'] > 0) {
            $parts[] = $totals['unknown'].' unknown';
        }

        return implode(' · ', $parts);
    }
}
