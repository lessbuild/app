<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Monitor;
use Illuminate\Database\Eloquent\Builder;

final class SuspendQueueMonitors
{
    /** Caller holds the application and environment locks in a transaction. */
    public function environment(int $environmentId, bool $revoke = false): void
    {
        $this->suspend(Monitor::query()->where('environment_id', $environmentId), $revoke);
    }

    /** Caller holds the application lock in a transaction. */
    public function application(int $applicationId): void
    {
        $this->suspend(Monitor::query()->whereIn('environment_id',
            Environment::withTrashed()->where('application_id', $applicationId)->select('id')), true);
    }

    /** Caller holds the source and monitor locks in a transaction. */
    public function monitor(Monitor $monitor, bool $revoke = false): void
    {
        $monitor->forceFill(['enabled' => false, 'next_check_at' => null, 'queue_snapshot_id' => null,
            'state_version' => $monitor->state_version + 1, 'config_revision' => $monitor->config_revision + 1,
            'health' => 'unknown', 'checked_at' => null, 'observation' => null, 'failure_streak' => 0, 'recovery_streak' => 0,
            ...($revoke ? ['queue_token_hash' => null] : []),
        ])->save();
        $incident = $monitor->incidents()->where('active_slot', true)->lockForUpdate()->first();
        $incident?->activities()->create(['action' => 'monitor_paused']);
    }

    /** @param Builder<Monitor> $query */
    private function suspend(Builder $query, bool $revoke): void
    {
        $query->where('type', 'queue')->orderBy('id')->chunkById(100, function ($monitors) use ($revoke): void {
            foreach ($monitors as $monitor) {
                $locked = Monitor::query()->lockForUpdate()->find($monitor->id);
                if ($locked !== null && ($locked->enabled || ($revoke && $locked->queue_token_hash !== null))) {
                    $this->monitor($locked, $revoke);
                }
            }
        });
    }
}
