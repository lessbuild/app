<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\Dashboard;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\DashboardsQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Billing\Entitlements;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

/** The account's saved dashboards. */
final class ShowDashboardsController
{
    /**
     * Show the dashboards page and the plan's dashboard limit.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  DashboardsQuery  $dashboards
     * @param  Entitlements  $entitlements
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, DashboardsQuery $dashboards, Entitlements $entitlements): View
    {
        return view('monitoring.dashboards', [
            'overview' => $overview->handle($project, $user),
            'dashboards' => $dashboards->handle($project->account_id),
            'limit' => $entitlements->for($project->account)->limit('monitoring.dashboards.max'),
            'canManage' => $user->can('create', [Dashboard::class, $project]),
        ]);
    }
}
