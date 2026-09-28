<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\SaveDashboard;
use App\Http\Requests\Monitoring\DashboardRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class StoreDashboardController
{
    /**
     * Create a dashboard.
     *
     * @param  DashboardRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  SaveDashboard  $save
     * @return RedirectResponse
     */
    public function __invoke(DashboardRequest $request, #[CurrentUser] User $user, Project $project, SaveDashboard $save): RedirectResponse
    {
        $dashboard = $save->handle($project->account, $user, $request->validated());

        return to_route('monitoring.dashboards.show', [$project, $dashboard->id])->with('status', __('Dashboard saved.'));
    }
}
