<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\DeleteMaintenanceWindow;
use App\Models\MaintenanceWindow;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteMaintenanceWindowController
{
    /**
     * Deletes a maintenance window.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  MaintenanceWindow  $window
     * @param  DeleteMaintenanceWindow  $delete
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, MaintenanceWindow $window, DeleteMaintenanceWindow $delete): RedirectResponse
    {
        $delete->handle($project->account, $user, $window);

        return to_route('monitoring.maintenance', $project)->with('status', __('Maintenance window deleted.'));
    }
}
