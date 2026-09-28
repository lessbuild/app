<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\Dashboard;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class EditDashboardController
{
    /**
     * Show the dashboard form, filled in.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Dashboard  $dashboard
     * @param  ProjectOverviewQuery  $overview
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Dashboard $dashboard, ProjectOverviewQuery $overview): View
    {

        return view('monitoring.dashboard-form', ['overview' => $overview->handle($project, $user), 'dashboard' => $dashboard]);
    }
}
