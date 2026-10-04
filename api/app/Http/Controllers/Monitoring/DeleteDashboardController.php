<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\DeleteDashboard;
use App\Models\Dashboard;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class DeleteDashboardController
{
    /**
     * Delete a dashboard.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Dashboard  $dashboard
     * @param  DeleteDashboard  $delete
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Dashboard $dashboard, DeleteDashboard $delete): JsonResponse
    {
        $delete->handle($project->account, $user, $dashboard);

        return response()->json(['redirect' => route('monitoring.dashboards', $project, false), 'message' => __('Dashboard deleted.')]);
    }
}
