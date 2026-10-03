<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\RunScheduledTaskNow;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class RunScheduledTaskController
{
    /**
     * Run a scheduled task now and return to the environment's Automation tab.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  string  $task
     * @param  RunScheduledTaskNow  $run
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Environment $environment, string $task, RunScheduledTaskNow $run): RedirectResponse
    {
        $run->handle($user, $environment->scheduledTasks()->findOrFail((int) $task));

        return to_route('deploy.environments.show', [$project, $environment, 'tab' => 'automation'])->with('status', __('Task queued.'));
    }
}
