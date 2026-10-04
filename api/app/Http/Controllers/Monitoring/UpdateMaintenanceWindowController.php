<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\SaveMaintenanceWindow;
use App\Http\Requests\Monitoring\MaintenanceWindowRequest;
use App\Models\MaintenanceWindow;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class UpdateMaintenanceWindowController
{
    /**
     * Save a maintenance window.
     *
     * @param  MaintenanceWindowRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  MaintenanceWindow  $window
     * @param  SaveMaintenanceWindow  $save
     * @return JsonResponse
     */
    public function __invoke(MaintenanceWindowRequest $request, #[CurrentUser] User $user, Project $project, MaintenanceWindow $window, SaveMaintenanceWindow $save): JsonResponse
    {
        $save->handle($project->account, $user, $request->validated(), $window);

        return response()->json(['redirect' => route('monitoring.maintenance', $project, false), 'message' => __('Maintenance window saved.')]);
    }
}
