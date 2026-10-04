<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\Monitor;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\MonitorFormQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class EditMonitorController
{
    /**
     * Describe the form for editing a monitor, with its current settings (never its secrets).
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Monitor  $monitor
     * @param  ProjectOverviewQuery  $overview
     * @param  MonitorFormQuery  $form
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Monitor $monitor, ProjectOverviewQuery $overview, MonitorFormQuery $form): JsonResponse
    {
        return response()->json(['overview' => $overview->handle($project, $user), ...$form->handle($project, $monitor)]);
    }
}
