<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\User;
use App\Services\Monitoring\HeartbeatSchedule;
use App\Services\Monitoring\MonitorChanges;
use App\Services\Monitoring\QueueMonitorEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class PauseMonitor
{
    /**
     * Create a new PauseMonitor instance.
     *
     * Pauses a monitor or turns it back on, without touching the rest of its settings.
     *
     * @param  HeartbeatSchedule  $heartbeatSchedule  Restarts a heartbeat monitor's schedule when it's turned back on.
     * @param  QueueMonitorEvaluator  $queues  Resets a queue monitor's evaluation when it's turned back on.
     * @param  MonitorChanges  $changes  Locks the configuration, checks the version and cancels checks that are waiting.
     * @param  RecordAuditEntry  $audit  Records the change.
     */
    public function __construct(
        private readonly HeartbeatSchedule $heartbeatSchedule,
        private readonly QueueMonitorEvaluator $queues,
        private readonly MonitorChanges $changes,
        private readonly RecordAuditEntry $audit,
    ) {}

    /**
     * Pause the monitor (no more checks, its waiting checks cancelled) or turn it back on (checked straight away). An
     * open incident stays open and notes the change; the monitor's health starts again from unknown.
     *
     * @param  Project  $project
     * @param  User  $actor
     * @param  Monitor  $monitor
     * @param  bool  $enabled  whether it should run
     * @param  int  $version  the version the person saw, so a change made meanwhile isn't overwritten
     * @return Monitor
     */
    public function handle(Project $project, User $actor, Monitor $monitor, bool $enabled, int $version): Monitor
    {
        return DB::transaction(function () use ($project, $actor, $monitor, $enabled, $version): Monitor {
            Gate::forUser($actor)->authorize('update', $monitor);
            $environment = $this->changes->lockScope($project, $actor, $monitor->environment_id);
            $monitor = Monitor::query()->where('environment_id', $environment->id)->lockForUpdate()->findOrFail($monitor->id);
            $this->changes->version($monitor, $version);
            if ($monitor->enabled === $enabled) {
                return $monitor;
            }
            $now = CarbonImmutable::now('UTC');
            $monitor->incidents()->where('active_slot', true)->lockForUpdate()->first()
                ?->activities()->create(['actor_id' => $actor->id, 'action' => $enabled ? 'monitor_resumed' : 'monitor_paused']);
            $monitor->forceFill([
                'enabled' => $enabled,
                'config_revision' => $monitor->config_revision + 1,
                'health' => 'unknown', 'failure_streak' => 0, 'recovery_streak' => 0,
                'observation' => null, 'checked_at' => null, 'last_scheduled_at' => null,
                'next_check_at' => $enabled ? $now : null,
            ]);
            $this->changes->cancelChecks($monitor);
            if ($monitor->type === 'heartbeat') {
                $this->heartbeatSchedule->reset($monitor, $now);
            } elseif ($monitor->type === 'queue') {
                $this->queues->reset($monitor, $now);
            }
            $monitor->forceFill(['state_version' => $monitor->state_version + 1])->save();
            $this->audit->handle(AuditAction::MonitorUpdated, $actor, $project->account_id, [
                'monitor' => $monitor->name, 'project' => $project->name, 'type' => $monitor->type,
                'environment' => $environment->name, 'enabled' => $enabled,
            ], $project->id);

            return $monitor;
        }, attempts: 3);
    }
}
