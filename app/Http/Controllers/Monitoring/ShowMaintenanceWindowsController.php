<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\MaintenanceWindow;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

/** The account's maintenance windows: while one is active, no project in the account opens incidents from monitors. */
final class ShowMaintenanceWindowsController
{
    /**
     * Maintenance windows that ended in the last 30 days or haven't ended yet.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview): View
    {
        $windows = MaintenanceWindow::query()->where('account_id', $project->account_id)
            ->where('ends_at', '>', now('UTC')->subDays(30))->orderByDesc('starts_at')->get();

        return view('monitoring.maintenance', [
            'overview' => $overview->handle($project, $user),
            'windows' => $windows,
            'canManage' => $user->can('create', [MaintenanceWindow::class, $project]),
        ]);
    }
}
