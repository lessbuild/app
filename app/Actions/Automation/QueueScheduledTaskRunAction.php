<?php

namespace App\Actions\Automation;

use App\Jobs\RunScheduledTaskJob;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskRun;
use App\Services\Entitlements;

class QueueScheduledTaskRunAction
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Queue a manual task run unless the task's non-overlap rule sees an active run.
     *
     * The run row and task timestamp intentionally remain separate writes, matching the existing
     * dispatch sequence; the worker job retains its own unique lock and execution safeguards.
     *
     * @param  ScheduledTask  $task  Task whose environment supplies authorization and entitlement context.
     * @return ScheduledTaskRun|null The queued run, or null when an active run blocks it.
     */
    public function handle(ScheduledTask $task): ?ScheduledTaskRun
    {
        $this->entitlements->enforce($task->environment->project->organization, 'scheduled_deployments');
        if ($task->without_overlapping && $task->runs()->whereIn('status', ['queued', 'running'])->exists()) {
            return null;
        }

        $run = $task->runs()->create(['status' => 'queued']);
        $task->update(['last_queued_at' => now()]);
        RunScheduledTaskJob::dispatch($run->id);

        return $run;
    }
}
