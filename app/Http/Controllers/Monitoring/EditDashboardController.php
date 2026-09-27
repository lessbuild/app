<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\DashboardsQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

final class EditDashboardController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $dashboard, ProjectOverviewQuery $overview, DashboardsQuery $dashboards): View
    {
        $record = $dashboards->find($project->account_id, $dashboard);
        Gate::authorize('update', $project->account);

        return view('monitoring.dashboard-form', ['overview' => $overview->handle($project, $user), 'dashboard' => $record]);
    }
}
