<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\Monitor;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\ProjectMonitorsQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowMonitorsController
{
    /**
     * Show the project's monitors.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ProjectMonitorsQuery  $monitors
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectMonitorsQuery $monitors): View
    {
        return view('monitoring.monitors', [
            'overview' => $overview->handle($project, $user),
            'monitors' => $monitors->handle($project),
            'canManage' => $user->can('create', [Monitor::class, $project]),
        ]);
    }
}
