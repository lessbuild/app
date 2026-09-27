<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\DeleteDashboard;
use App\Models\Dashboard;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteDashboardController
{
    /**
     * Deletes a dashboard.
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Dashboard $dashboard, DeleteDashboard $delete): RedirectResponse
    {
        $delete->handle($project->account, $user, $dashboard);

        return to_route('monitoring.dashboards', $project)->with('status', __('Dashboard deleted.'));
    }
}
