<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Data\Telemetry\MonitorObservation;
use App\Modules\Monitor\Models\HeartbeatRun;
use App\Modules\Monitor\Models\Monitor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class RecordHeartbeat
{
    public const MAX_ACTIVE_RUNS = 100;

    public function __construct(
        private readonly MonitorQueue $queue,
        private readonly HeartbeatSchedule $schedules,
        private readonly RecordMonitorResult $results,
    ) {}

    /** @return array{run_id: string, signal: string, replayed: bool, received_at: string} */
    public function receive(int $monitorId, string $tokenHash, string $runId, string $signal): array
    {
        return DB::connection('monitor')->transaction(function () use ($monitorId, $tokenHash, $runId, $signal): array {
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
            $scheduledDeadline = $monitor->heartbeat_due_at?->addMinutes($monitor->heartbeat_grace_minutes);
            $this->evaluate($monitor, $now);
            if ($run === null) {
                abort_if($signal === 'start' && $monitor->heartbeatRuns()->where('status', 'running')->count() >= self::MAX_ACTIVE_RUNS,
                    429, 'This monitor already has 100 unfinished runs.');
                $run = $monitor->heartbeatRuns()->make()->forceFill([
                    'run_id' => $runId, 'config_revision' => $monitor->config_revision,
                    'status' => $signal === 'start' ? 'running' : ($signal === 'success' ? 'success' : 'failed'),
                    'started_at' => $signal === 'start' ? $now : null,
                    'deadline_at' => $signal === 'start' ? $now->addMinutes($monitor->heartbeat_grace_minutes) : $scheduledDeadline,
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
                $this->observe($monitor, $signal === 'success' ? 'up' : 'down',
                    $signal === 'success' ? 'heartbeat_success' : 'heartbeat_failure', $now, $run, $current);
            }
            $this->scheduleDeadline($monitor);

            return $this->receipt($run, $signal, false);
        }, attempts: 3);
    }

    /** Caller holds the source and monitor locks in a transaction. */
    public function evaluate(Monitor $monitor, CarbonImmutable $now): void
    {
        $expired = $monitor->heartbeatRuns()->where('config_revision', $monitor->config_revision)
            ->where('status', 'running')->where('deadline_at', '<=', $now->format('Y-m-d H:i:s.u'))
            ->orderBy('deadline_at')->orderBy('id')->limit(self::MAX_ACTIVE_RUNS)->lockForUpdate()->get();
        $currentExpired = false;
        foreach ($expired as $run) {
            $run->forceFill(['status' => 'timed_out'])->save();
            $current = $monitor->heartbeat_sequence === $run->id;
            $currentExpired = $currentExpired || $current;
            $this->observe($monitor, 'down', 'heartbeat_timeout', $now, $run, $current);
        }
        if ($monitor->heartbeat_due_at !== null && $monitor->heartbeat_due_at->addMinutes($monitor->heartbeat_grace_minutes)->lte($now)) {
            if (! $currentExpired) {
                $this->observe($monitor, 'down', 'heartbeat_missing', $now);
            }
            $monitor->forceFill(['heartbeat_due_at' => null]);
        }
        $this->scheduleDeadline($monitor);
    }

    private function scheduleDeadline(Monitor $monitor): void
    {
        $first = $monitor->heartbeatRuns()->where('config_revision', $monitor->config_revision)
            ->where('status', 'running')->orderBy('deadline_at')->orderBy('id')->first(['deadline_at']);
        $next = $monitor->heartbeat_due_at?->addMinutes($monitor->heartbeat_grace_minutes);
        if ($first !== null && ($next === null || $first->deadline_at->lt($next))) {
            $next = $first->deadline_at;
        }
        $monitor->forceFill(['next_check_at' => $monitor->enabled ? $next : null])->save();
    }

    private function observe(Monitor $monitor, string $outcome, string $reason, CarbonImmutable $now, ?HeartbeatRun $run = null, bool $current = true): void
    {
        $deadline = $run !== null ? $run->deadline_at : $monitor->heartbeat_due_at?->addMinutes($monitor->heartbeat_grace_minutes);
        $result = new MonitorObservation($outcome, $reason,
            durationMs: $run?->started_at === null ? null : max(0, $run->started_at->diffInMilliseconds($now)),
            details: ['type' => 'heartbeat', 'run_id' => $run?->run_id, 'affects_health' => $current,
                'deadline_at' => $deadline?->toISOString(), 'received_at' => $now->toISOString(),
                'started_at' => $run?->started_at?->toISOString()]);
        $monitor->checks()->make()->forceFill([
            ...$result->toArray(), 'config_revision' => $monitor->config_revision,
            'scheduled_at' => $now, 'started_at' => $now, 'finished_at' => $now,
            'status' => 'completed', 'location' => 'Heartbeat receiver', 'scheduled_slot' => null,
        ])->save();
        if ($current) {
            $this->results->record($monitor, $result, $now, 'Heartbeat receiver');
        }
    }

    /** @return array{run_id: string, signal: string, replayed: bool, received_at: string} */
    private function receipt(HeartbeatRun $run, string $signal, bool $replayed): array
    {
        return ['run_id' => $run->run_id, 'signal' => $signal, 'replayed' => $replayed,
            'received_at' => ($signal === 'start' ? $run->started_at : $run->finished_at)->toISOString()];
    }
}
