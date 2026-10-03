<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\AlertDestination;
use App\Models\OnCallSchedule;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\AlertDestinationsQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

/** The account's alert destinations. They are shared by every project's monitors. */
final class ShowAlertDestinationsController
{
    /**
     * Show the destinations page.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  AlertDestinationsQuery  $destinations
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, AlertDestinationsQuery $destinations): View
    {
        return view('monitoring.destinations', [
            'overview' => $overview->handle($project, $user),
            'destinations' => $destinations->handle($project->account_id),
            'members' => $destinations->recipients($project->account_id),
            'schedules' => OnCallSchedule::query()->where('account_id', $project->account_id)->orderBy('name')->get(['id', 'name'])->all(),
            'canManage' => $user->can('create', [AlertDestination::class, $project]),
        ]);
    }
}
