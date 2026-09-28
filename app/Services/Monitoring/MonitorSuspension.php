<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Models\Monitor;

/** Pauses heartbeat and queue monitors whose key was revoked, so stale signals can't change their health. */
final class MonitorSuspension
{
    /** Caller holds the source and monitor locks in a transaction. */
    public function heartbeat(Monitor $monitor, bool $revoke = false): void
    {
        $monitor->heartbeatRuns()->whereIn('status', ['running', 'timed_out'])->whereNull('terminal_signal')
            ->update(['status' => 'cancelled']);
        $this->pause($monitor, ['heartbeat_due_at' => null, 'heartbeat_sequence' => null,
            ...($revoke ? ['heartbeat_token_hash' => null] : [])]);
    }

    /** Caller holds the source and monitor locks in a transaction. */
    public function queue(Monitor $monitor, bool $revoke = false): void
    {
        $this->pause($monitor, ['queue_snapshot_id' => null, ...($revoke ? ['queue_token_hash' => null] : [])]);
    }

    /**
     * Disables the monitor and resets its health and configuration revision, so any signal still in flight is ignored,
     * and notes it on the open incident.
     *
     * @param  array<string, mixed>  $values
     */
    private function pause(Monitor $monitor, array $values): void
    {
        $monitor->forceFill([
            'enabled' => false, 'next_check_at' => null,
            'state_version' => $monitor->state_version + 1, 'config_revision' => $monitor->config_revision + 1,
            'health' => 'unknown', 'checked_at' => null, 'observation' => null, 'failure_streak' => 0, 'recovery_streak' => 0,
            ...$values,
        ])->save();
        $incident = $monitor->incidents()->where('active_slot', true)->lockForUpdate()->first();
        $incident?->activities()->create(['action' => 'monitor_paused']);
    }
}
