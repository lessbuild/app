<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\DeleteDashboard;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\DashboardsQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteDashboardController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $dashboard, DashboardsQuery $dashboards, DeleteDashboard $delete): RedirectResponse
    {
        $delete->handle($project->account, $user, $dashboards->find($project->account_id, $dashboard));

        return to_route('monitoring.dashboards', $project)->with('status', __('Dashboard deleted.'));
    }
}
