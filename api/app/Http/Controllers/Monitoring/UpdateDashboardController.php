<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\SaveDashboard;
use App\Http\Requests\Monitoring\DashboardRequest;
use App\Models\Dashboard;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class UpdateDashboardController
{
    /**
     * Save a dashboard.
     *
     * @param  DashboardRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Dashboard  $dashboard
     * @param  SaveDashboard  $save
     * @return JsonResponse
     */
    public function __invoke(DashboardRequest $request, #[CurrentUser] User $user, Project $project, Dashboard $dashboard, SaveDashboard $save): JsonResponse
    {
        $record = $save->handle($project->account, $user, $request->validated(), $dashboard);

        return response()->json(['redirect' => route('monitoring.dashboards.show', [$project, $record->id], false), 'message' => __('Dashboard saved.')]);
    }
}
