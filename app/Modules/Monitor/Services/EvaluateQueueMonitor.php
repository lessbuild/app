<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Data\Telemetry\MonitorObservation;
use App\Modules\Monitor\Data\Telemetry\QueueMonitorSettings;
use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\QueueSnapshot;
use App\Modules\Monitor\Models\QueueWorker;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class EvaluateQueueMonitor
{
    public const MAX_LIVE_WORKERS = 100;

    public function __construct(private readonly RecordMonitorResult $results) {}

    /** Caller holds source and monitor locks in a transaction. */
    public function evaluate(Monitor $monitor, CarbonImmutable $now, bool $recordSnapshot = false): void
    {
        $state = $this->inspect($monitor, $now);
        $result = $state['result'];
        $previous = $monitor->observation;
        $changed = $previous === null || $previous['outcome'] !== $result->outcome || $previous['reason'] !== $result->reason
            || ($previous['details']['breaches'] ?? []) !== $result->details['breaches']
            || ($previous['details']['missing_metrics'] ?? []) !== $result->details['missing_metrics'];
        if ($recordSnapshot || $changed) {
            $monitor->checks()->make()->forceFill([
                ...$result->toArray(), 'config_revision' => $monitor->config_revision,
                'scheduled_at' => $now, 'started_at' => $now, 'finished_at' => $now,
                'status' => 'completed', 'location' => 'Queue signals', 'scheduled_slot' => null,
            ])->save();
            $this->results->record($monitor, $result, $now, 'Queue signals');
        }
        $monitor->forceFill(['next_check_at' => $monitor->enabled ? $state['next'] : null])->save();
    }

    /** @return array{result: MonitorObservation, next: ?CarbonImmutable, snapshot: ?QueueSnapshot} */
    public function inspect(Monitor $monitor, CarbonImmutable $now): array
    {
        $settings = $monitor->queue_settings;
        $snapshot = $monitor->queue_snapshot_id === null ? null : $monitor->queueSnapshots()
            ->where('config_revision', $monitor->config_revision)->find($monitor->queue_snapshot_id);
        $fresh = $snapshot !== null && $snapshot->valid_until->gt($now);
        $reportDeadline = $snapshot?->valid_until ?? $monitor->queue_started_at->addSeconds($settings['report_timeout_seconds']);
        $workerGrace = $monitor->queue_started_at->addSeconds($settings['worker_timeout_seconds']);
        $workers = $this->liveWorkers($monitor, $now)->orderBy('last_seen_at')->orderBy('id')
            ->limit(self::MAX_LIVE_WORKERS)->get(['id', 'status', 'last_seen_at', 'job_started_at']);
        $deadlines = [$reportDeadline];
        if ($settings['minimum_workers'] > 0) {
            $deadlines[] = $workerGrace;
        }
        $breaches = [];
        $missing = [];
        if (! $fresh && $reportDeadline->lte($now)) {
            $breaches[] = 'queue_report_missing';
        }
        $enoughWorkers = $workers->count() >= $settings['minimum_workers'];
        if (! $enoughWorkers && $workerGrace->lte($now)) {
            $breaches[] = 'queue_workers_missing';
        }
        $metrics = [];
        foreach (QueueMonitorSettings::METRICS as $metric) {
            $metrics[$metric] = $snapshot?->{$metric};
            $threshold = $settings['max_'.$metric] ?? null;
            if ($fresh && $threshold !== null) {
                if ($metrics[$metric] === null) {
                    $missing[] = $metric;
                } elseif ($metrics[$metric] > $threshold) {
                    $breaches[] = 'queue_'.$metric;
                }
            }
        }
        $longRunning = 0;
        foreach ($workers as $worker) {
            $deadlines[] = $worker->last_seen_at->addSeconds($settings['worker_timeout_seconds']);
            if ($worker->status === 'busy' && $settings['max_runtime_seconds'] !== null) {
                $runtimeDeadline = $worker->job_started_at->addSeconds($settings['max_runtime_seconds'] + 1);
                if ($runtimeDeadline->lte($now)) {
                    $longRunning++;
                }
                $deadlines[] = $runtimeDeadline;
            }
        }
        if ($longRunning > 0) {
            $breaches[] = 'queue_runtime';
        }
        $outcome = $breaches !== [] ? 'down' : ($fresh && $enoughWorkers && $missing === [] ? 'up' : 'unknown');
        $result = new MonitorObservation($outcome, $breaches[0] ?? ($outcome === 'up' ? 'queue_healthy' : 'queue_incomplete'),
            details: ['type' => 'queue', 'snapshot_id' => $snapshot?->snapshot_id, 'observed_at' => $snapshot?->observed_at->toISOString(),
                'fresh_snapshot' => $fresh, 'metrics' => $metrics, 'active_workers' => $workers->count(),
                'busy_workers' => $workers->where('status', 'busy')->count(), 'long_running_workers' => $longRunning,
                'breaches' => $breaches, 'missing_metrics' => $missing]);
        $next = null;
        foreach ($deadlines as $deadline) {
            if ($deadline->gt($now) && ($next === null || $deadline->lt($next))) {
                $next = $deadline;
            }
        }

        return ['result' => $result, 'next' => $next, 'snapshot' => $snapshot];
    }

    /** @return Builder<QueueWorker> */
    public function liveWorkers(Monitor $monitor, CarbonImmutable $now): Builder
    {
        return QueueWorker::query()->where('monitor_id', $monitor->id)->where('config_revision', $monitor->config_revision)
            ->whereIn('status', ['idle', 'busy'])
            ->where('last_seen_at', '>', $now->subSeconds($monitor->queue_settings['worker_timeout_seconds'])->format('Y-m-d H:i:s.u'));
    }

    public function reset(Monitor $monitor, CarbonImmutable $now): void
    {
        $settings = $monitor->queue_settings;
        $seconds = $settings['minimum_workers'] > 0
            ? min($settings['report_timeout_seconds'], $settings['worker_timeout_seconds']) : $settings['report_timeout_seconds'];
        $monitor->forceFill(['queue_snapshot_id' => null, 'queue_started_at' => $now,
            'next_check_at' => $monitor->enabled ? $now->addSeconds($seconds) : null]);
    }
}
