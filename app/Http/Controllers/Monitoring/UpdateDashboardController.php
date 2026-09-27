<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\SaveDashboard;
use App\Http\Requests\Monitoring\DashboardRequest;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\DashboardsQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class UpdateDashboardController
{
    public function __invoke(DashboardRequest $request, #[CurrentUser] User $user, Project $project, string $dashboard, DashboardsQuery $dashboards, SaveDashboard $save): RedirectResponse
    {
        $record = $save->handle($project->account, $user, $request->validated(), $dashboards->find($project->account_id, $dashboard));

        return to_route('monitoring.dashboards.show', [$project, $record->id])->with('status', __('Dashboard saved.'));
    }
}
