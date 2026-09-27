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
    public function __invoke(MaintenanceWindowRequest $request, #[CurrentUser] User $user, Project $project, string $window, SaveMaintenanceWindow $save): RedirectResponse
    {
        $target = MaintenanceWindow::query()->where('account_id', $project->account_id)->findOrFail((int) $window);
        $save->handle($project->account, $user, $request->validated(), $target);

        return to_route('monitoring.maintenance', $project)->with('status', __('Maintenance window saved.'));
    }
}
