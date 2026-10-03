<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Exceptions\StateConflict;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskRun;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Services\Deploy\Automation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class RunScheduledTaskNow
{
    /**
     * Create a new RunScheduledTaskNow instance.
     *
     * Runs tasks by hand.
     *
     * @param  Automation  $automation  Queues the run.
     * @param  Entitlements  $entitlements  Checks the plan still includes scheduled tasks.
     */
    public function __construct(private readonly Automation $automation, private readonly Entitlements $entitlements) {}

    /**
     * Queue a run of the task now, unless it skips overlapping runs and one hasn't finished.
     *
     * @param  User  $actor
     * @param  ScheduledTask  $task
     * @return ScheduledTaskRun
     */
    public function handle(User $actor, ScheduledTask $task): ScheduledTaskRun
    {
        Gate::forUser($actor)->authorize('configureDeploy', $task->environment);
        if (! $this->entitlements->for($task->environment->project->account)->has('deploy.scheduled')) {
            throw ValidationException::withMessages(['task' => __('Scheduled tasks come with the Pro Deploy plan and above.')]);
        }

        return $this->automation->queue($task, $actor) ?? throw new StateConflict(__('This task is already running.'));
    }
}
