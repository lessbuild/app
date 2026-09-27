<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Models\Monitor;
use App\Models\User;
use App\Services\Monitoring\IncidentLifecycle;
use App\Services\Monitoring\MonitorChanges;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ArchiveMonitor
{
    public function __construct(
        private readonly IncidentLifecycle $lifecycle,
        private readonly MonitorChanges $changes,
        private readonly RecordAuditEntry $audit,
    ) {}

    /** Stop a monitor and archive it. Its checks and incidents stay in the history. */
    public function handle(Monitor $monitor, User $actor, int $version): void
    {
        DB::transaction(function () use ($monitor, $actor, $version): void {
            $project = $monitor->environment->project;
            Gate::forUser($actor)->authorize('delete', $monitor);
            $environment = $this->changes->lockScope($project, $actor, $monitor->environment_id);
            $monitor = Monitor::query()->where('environment_id', $environment->id)->lockForUpdate()->findOrFail($monitor->id);
            $this->changes->version($monitor, $version);
            $incident = $monitor->incidents()->where('active_slot', true)->lockForUpdate()->first();
            if ($incident !== null) {
                $this->lifecycle->close($incident, 'monitor_archived', CarbonImmutable::now('UTC'), $actor);
            }
            $this->changes->cancelChecks($monitor);
            $monitor->forceFill([
                'enabled' => false, 'next_check_at' => null,
                'state_version' => $monitor->state_version + 1, 'config_revision' => $monitor->config_revision + 1,
                ...($monitor->type === 'heartbeat' ? ['heartbeat_token_hash' => null, 'heartbeat_due_at' => null] : []),
                ...($monitor->type === 'queue' ? ['queue_token_hash' => null, 'queue_snapshot_id' => null] : []),
            ])->save();
            $monitor->delete();
            $this->audit->handle(AuditAction::MonitorArchived, $actor, $project->account_id, [
                'monitor' => $monitor->name, 'project' => $project->name, 'type' => $monitor->type, 'environment' => $environment->name,
            ], $project->id);
        }, attempts: 3);
    }
}
