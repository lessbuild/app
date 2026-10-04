<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\DeleteMaintenanceWindow;
use App\Models\MaintenanceWindow;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class DeleteMaintenanceWindowController
{
    /**
     * Delete a maintenance window.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  MaintenanceWindow  $window
     * @param  DeleteMaintenanceWindow  $delete
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, MaintenanceWindow $window, DeleteMaintenanceWindow $delete): JsonResponse
    {
        $delete->handle($project->account, $user, $window);

        return response()->json(['redirect' => route('monitoring.maintenance', $project, false), 'message' => __('Maintenance window deleted.')]);
    }
}
