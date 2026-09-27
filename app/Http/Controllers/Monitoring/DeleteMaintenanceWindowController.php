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
    public function __invoke(#[CurrentUser] User $user, Project $project, string $window, DeleteMaintenanceWindow $delete): RedirectResponse
    {
        $delete->handle($project->account, $user, MaintenanceWindow::query()->where('account_id', $project->account_id)->findOrFail((int) $window));

        return to_route('monitoring.maintenance', $project)->with('status', __('Maintenance window deleted.'));
    }
}
