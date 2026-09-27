<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\SaveDashboard;
use App\Http\Requests\Monitoring\DashboardRequest;
use App\Models\Dashboard;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class UpdateDashboardController
{
    /**
     * Saves a dashboard.
     */
    public function __invoke(DashboardRequest $request, #[CurrentUser] User $user, Project $project, Dashboard $dashboard, SaveDashboard $save): RedirectResponse
    {
        $record = $save->handle($project->account, $user, $request->validated(), $dashboard);

        return to_route('monitoring.dashboards.show', [$project, $record->id])->with('status', __('Dashboard saved.'));
    }
}
