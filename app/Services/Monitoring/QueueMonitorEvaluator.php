<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Data\Monitoring\MonitorObservation;
use App\Models\Monitor;
use App\Models\QueueSnapshot;
use App\Models\QueueWorker;
use App\Support\Monitoring\QueueMonitorSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class QueueMonitorEvaluator
{
    public const MAX_LIVE_WORKERS = 100;

    /**
     * Create a new QueueMonitorEvaluator instance.
     *
     * Judges queue monitors from their reports and workers.
     *
     * @param  MonitorResults  $results  Records each judgement.
     */
    public function __construct(private readonly MonitorResults $results) {}

    /**
     * Judge the queue monitor now and record the result, storing a check when the outcome or its reasons changed.
     *
     * Caller holds source and monitor locks in a transaction.
     *
     * @param  Monitor  $monitor
     * @param  CarbonImmutable  $now
     * @param  bool  $recordSnapshot
     * @return void
     */
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

    /**
     * Judge a queue monitor now: missing or stale reports, too few live workers (after their grace period), metrics
     * over their thresholds, and jobs running too long each breach it; missing data leaves it unknown. Also returns
     * when it next needs looking at.
     *
     * @param  Monitor  $monitor
     * @param  CarbonImmutable  $now
     * @return array{result: MonitorObservation, next: ?CarbonImmutable, snapshot: ?QueueSnapshot}
     */
    public function inspect(Monitor $monitor, CarbonImmutable $now): array
    {
        $settings = $monitor->queueThresholds();
        $startedAt = $monitor->queue_started_at ?? $now;
        $snapshot = $monitor->queue_snapshot_id === null ? null : $monitor->queueSnapshots()
            ->where('config_revision', $monitor->config_revision)->find($monitor->queue_snapshot_id);
        $fresh = $snapshot !== null && $snapshot->valid_until->gt($now);
        $reportDeadline = $snapshot !== null ? $snapshot->valid_until : $startedAt->addSeconds($settings['report_timeout_seconds']);
        $workerGrace = $startedAt->addSeconds($settings['worker_timeout_seconds']);
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
                $runtimeDeadline = ($worker->job_started_at ?? $worker->last_seen_at)->addSeconds($settings['max_runtime_seconds'] + 1);
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

    /**
     * Query the monitor's workers under its current configuration that are idle or busy and were seen within the
     * worker timeout.
     *
     * @param  Monitor  $monitor
     * @param  CarbonImmutable  $now
     * @return Builder<QueueWorker>
     */
    public function liveWorkers(Monitor $monitor, CarbonImmutable $now): Builder
    {
        return QueueWorker::query()->where('monitor_id', $monitor->id)->where('config_revision', $monitor->config_revision)
            ->whereIn('status', ['idle', 'busy'])
            ->where('last_seen_at', '>', $now->subSeconds($monitor->queueThresholds()['worker_timeout_seconds'])->format('Y-m-d H:i:s.u'));
    }

    /**
     * Start evaluation afresh after its settings change, giving collectors and workers until the shorter timeout to
     * report.
     *
     * @param  Monitor  $monitor
     * @param  CarbonImmutable  $now
     * @return void
     */
    public function reset(Monitor $monitor, CarbonImmutable $now): void
    {
        $settings = $monitor->queueThresholds();
        $seconds = $settings['minimum_workers'] > 0
            ? min($settings['report_timeout_seconds'], $settings['worker_timeout_seconds']) : $settings['report_timeout_seconds'];
        $monitor->forceFill(['queue_snapshot_id' => null, 'queue_started_at' => $now,
            'next_check_at' => $monitor->enabled ? $now->addSeconds($seconds) : null]);
    }
}
