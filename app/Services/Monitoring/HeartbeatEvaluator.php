<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Data\Monitoring\MonitorObservation;
use App\Models\HeartbeatRun;
use App\Models\Monitor;
use Carbon\CarbonImmutable;

/** Turns heartbeat runs and missed deadlines into monitor results. */
final class HeartbeatEvaluator
{
    public const MAX_ACTIVE_RUNS = 100;

    public function __construct(private readonly MonitorResults $results) {}

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
        if ($monitor->heartbeat_due_at !== null && $monitor->heartbeat_due_at->addMinutes((int) $monitor->heartbeat_grace_minutes)->lte($now)) {
            if (! $currentExpired) {
                $this->observe($monitor, 'down', 'heartbeat_missing', $now);
            }
            $monitor->forceFill(['heartbeat_due_at' => null]);
        }
        $this->scheduleDeadline($monitor);
    }

    public function scheduleDeadline(Monitor $monitor): void
    {
        $first = $monitor->heartbeatRuns()->where('config_revision', $monitor->config_revision)
            ->where('status', 'running')->orderBy('deadline_at')->orderBy('id')->first(['deadline_at']);
        $next = $monitor->heartbeat_due_at?->addMinutes((int) $monitor->heartbeat_grace_minutes);
        if ($first?->deadline_at !== null && ($next === null || $first->deadline_at->lt($next))) {
            $next = $first->deadline_at;
        }
        $monitor->forceFill(['next_check_at' => $monitor->enabled ? $next : null])->save();
    }

    public function observe(Monitor $monitor, string $outcome, string $reason, CarbonImmutable $now, ?HeartbeatRun $run = null, bool $current = true): void
    {
        $deadline = $run !== null ? $run->deadline_at : $monitor->heartbeat_due_at?->addMinutes((int) $monitor->heartbeat_grace_minutes);
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
}
