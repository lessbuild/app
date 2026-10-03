<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Models\HeartbeatRun;
use App\Services\Monitoring\HeartbeatEvaluator;
use App\Services\Monitoring\HeartbeatSchedule;
use App\Services\Monitoring\MonitorQueue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class RecordHeartbeat
{
    /**
     * Create a new RecordHeartbeat instance.
     *
     * Records a start, success or failure ping from a job a heartbeat monitor watches.
     *
     * @param  MonitorQueue  $queue  Locks the monitor and checks it still accepts pings.
     * @param  HeartbeatSchedule  $schedules  Works out when the next run is due.
     * @param  HeartbeatEvaluator  $evaluator  Decides what the ping means for the monitor's health.
     */
    public function __construct(
        private readonly MonitorQueue $queue,
        private readonly HeartbeatSchedule $schedules,
        private readonly HeartbeatEvaluator $evaluator,
    ) {}

    /**
     * Record a start, success or failure signal from a heartbeat run (the public heartbeat API).
     *
     * @param  int  $monitorId
     * @param  string  $tokenHash
     * @param  string  $runId
     * @param  string  $signal
     * @return array{run_id: string, signal: string, replayed: bool, received_at: string}
     */
    public function handle(int $monitorId, string $tokenHash, string $runId, string $signal): array
    {
        return DB::transaction(function () use ($monitorId, $tokenHash, $runId, $signal): array {
            $monitor = $this->queue->lockMonitor($monitorId);
            abort_unless($this->queue->eligible($monitor) && $monitor->type === 'heartbeat'
                && is_string($monitor->heartbeat_token_hash) && hash_equals($monitor->heartbeat_token_hash, $tokenHash),
                401, 'The heartbeat key is invalid or the source is unavailable.');
            $run = $monitor->heartbeatRuns()->where('run_id', $runId)->lockForUpdate()->first();
            if ($run !== null) {
                abort_unless($run->config_revision === $monitor->config_revision && $run->status !== 'cancelled',
                    409, 'This run belongs to an earlier monitor configuration. Use a new run ID.');
                $replayed = $signal === 'start' ? $run->started_at !== null : $run->terminal_signal === $signal;
                if ($replayed) {
                    return $this->receipt($run, $signal, true);
                }
                abort_if($run->terminal_signal !== null || $signal === 'start', 409, 'This run already has a different terminal signal.');
            }

            $now = CarbonImmutable::now('UTC');
            $scheduledDeadline = $monitor->heartbeat_due_at?->addMinutes((int) $monitor->heartbeat_grace_minutes);
            $this->evaluator->evaluate($monitor, $now);
            if ($run === null) {
                abort_if($signal === 'start' && $monitor->heartbeatRuns()->where('status', 'running')->count() >= HeartbeatEvaluator::MAX_ACTIVE_RUNS,
                    429, 'This monitor already has 100 unfinished runs.');
                $run = $monitor->heartbeatRuns()->make()->forceFill([
                    'run_id' => $runId, 'config_revision' => $monitor->config_revision,
                    'status' => $signal === 'start' ? 'running' : ($signal === 'success' ? 'success' : 'failed'),
                    'started_at' => $signal === 'start' ? $now : null,
                    'deadline_at' => $signal === 'start' ? $now->addMinutes((int) $monitor->heartbeat_grace_minutes) : $scheduledDeadline,
                ]);
                $run->save();
                $monitor->forceFill(['heartbeat_sequence' => $run->id]);
            } else {
                $run->refresh();
            }

            $monitor->forceFill(['heartbeat_received_at' => $now]);
            if ($signal === 'start') {
                if ($monitor->heartbeat_due_at === null) {
                    $monitor->forceFill(['heartbeat_due_at' => $this->schedules->next($monitor, $now)]);
                }
            } else {
                $run->forceFill(['terminal_signal' => $signal, 'finished_at' => $now,
                    'status' => $signal === 'success' ? 'success' : 'failed'])->save();
                $current = $monitor->heartbeat_sequence === $run->id;
                if ($current) {
                    $monitor->forceFill(['heartbeat_due_at' => $this->schedules->next($monitor, $now)]);
                    if ($signal === 'success') {
                        $monitor->forceFill(['heartbeat_succeeded_at' => $now]);
                    }
                }
                $this->evaluator->observe($monitor, $signal === 'success' ? 'up' : 'down',
                    $signal === 'success' ? 'heartbeat_success' : 'heartbeat_failure', $now, $run, $current);
            }
            $this->evaluator->scheduleDeadline($monitor);

            return $this->receipt($run, $signal, false);
        }, attempts: 3);
    }

    /**
     * Build what the job gets back: the run ID, the signal, whether it was a replay, and when it was recorded.
     *
     * @param  HeartbeatRun  $run
     * @param  string  $signal
     * @param  bool  $replayed
     * @return array{run_id: string, signal: string, replayed: bool, received_at: string}
     */
    private function receipt(HeartbeatRun $run, string $signal, bool $replayed): array
    {
        return ['run_id' => $run->run_id, 'signal' => $signal, 'replayed' => $replayed,
            'received_at' => (string) (($signal === 'start' ? $run->started_at : $run->finished_at) ?? now('UTC'))->toISOString()];
    }
}
