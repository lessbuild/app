<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\MonitorFormQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class CreateMonitorController
{
    /**
     * Describe the form for adding a monitor.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  MonitorFormQuery  $form
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, MonitorFormQuery $form): JsonResponse
    {
        return response()->json(['overview' => $overview->handle($project, $user), ...$form->handle($project, null)]);
    }
}
