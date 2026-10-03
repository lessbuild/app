<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\SaveMaintenanceWindow;
use App\Http\Requests\Monitoring\MaintenanceWindowRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class StoreMaintenanceWindowController
{
    /**
     * Schedule a maintenance window.
     *
     * @param  MaintenanceWindowRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  SaveMaintenanceWindow  $save
     * @return RedirectResponse
     */
    public function __invoke(MaintenanceWindowRequest $request, #[CurrentUser] User $user, Project $project, SaveMaintenanceWindow $save): RedirectResponse
    {
        $save->handle($project->account, $user, $request->validated());

        return to_route('monitoring.maintenance', $project)->with('status', __('Maintenance window scheduled.'));
    }
}
