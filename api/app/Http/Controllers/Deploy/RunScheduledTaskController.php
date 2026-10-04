<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\RunScheduledTaskNow;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

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
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Environment $environment, string $task, RunScheduledTaskNow $run): JsonResponse
    {
        $run->handle($user, $environment->scheduledTasks()->findOrFail((int) $task));

        return response()->json(['redirect' => route('deploy.environments.show', [$project, $environment, 'tab' => 'automation'], false), 'message' => __('Task queued.')]);
    }
}
