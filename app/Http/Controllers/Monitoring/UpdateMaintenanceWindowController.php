<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\SaveMaintenanceWindow;
use App\Http\Requests\Monitoring\MaintenanceWindowRequest;
use App\Models\MaintenanceWindow;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class UpdateMaintenanceWindowController
{
    /**
     * Saves a maintenance window.
     */
    public function __invoke(MaintenanceWindowRequest $request, #[CurrentUser] User $user, Project $project, MaintenanceWindow $window, SaveMaintenanceWindow $save): RedirectResponse
    {
        $save->handle($project->account, $user, $request->validated(), $window);

        return to_route('monitoring.maintenance', $project)->with('status', __('Maintenance window saved.'));
    }
}
