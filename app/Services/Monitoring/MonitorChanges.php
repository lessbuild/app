<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use App\Models\Environment;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Shared steps for changing a monitor: locking, authorising, version checks and cancelling pending checks. */
final class MonitorChanges
{
    /** Lock the project and the environment (in that order) and check the actor may manage the project's monitors. */
    public function lockScope(Project $project, User $actor, string $environmentId): Environment
    {
        $project = Project::query()->lockForUpdate()->findOrFail($project->id);
        Gate::forUser($actor)->authorize('manageService', [$project, 'monitoring']);
        abort_unless($actor->hasVerifiedEmail(), 403);

        return Environment::query()->where('project_id', $project->id)->lockForUpdate()->findOrFail($environmentId);
    }

    public function version(Monitor $monitor, int $version): void
    {
        abort_unless($monitor->state_version === $version, 409, __('This monitor changed. Refresh before trying again.'));
    }

    public function cancelChecks(Monitor $monitor): void
    {
        if ($monitor->type === 'heartbeat') {
            $monitor->heartbeatRuns()->whereIn('status', ['running', 'timed_out'])->whereNull('terminal_signal')
                ->update(['status' => 'cancelled']);
        }
        $jobs = $monitor->checks()->whereIn('status', ['queued', 'running'])->whereNotNull('queue_job_uuid')->select('queue_job_uuid');
        DB::table('jobs')->where('queue', MonitorQueue::NAME)->whereIn('job_uuid', $jobs)->whereNull('reserved_at')->delete();
        $monitor->checks()->whereIn('status', ['queued', 'running'])->update([
            'status' => 'cancelled', 'outcome' => 'unknown', 'reason' => 'source_changed',
            'finished_at' => now('UTC'), 'processing_token' => null, 'lease_until' => null,
        ]);
    }
}
