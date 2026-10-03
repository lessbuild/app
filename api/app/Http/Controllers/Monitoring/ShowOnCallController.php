<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\OnCallSchedule;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\AlertDestinationsQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Monitoring\OnCall;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowOnCallController
{
    /**
     * Show the account's on-call schedules: who's on call now, the next turns, and overrides.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  AlertDestinationsQuery  $destinations
     * @param  OnCall  $onCall
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, AlertDestinationsQuery $destinations, OnCall $onCall): View
    {
        $schedules = OnCallSchedule::query()->where('account_id', $project->account_id)->with(['members', 'overrides' => fn ($query) => $query->where('ends_at', '>', now())->with('user')->orderBy('starts_at')])->orderBy('name')->get();

        return view('monitoring.on-call', [
            'overview' => $overview->handle($project, $user),
            'schedules' => $schedules->map(fn (OnCallSchedule $schedule): array => [
                'schedule' => $schedule, 'now' => $onCall->current($schedule), 'upcoming' => $onCall->upcoming($schedule, 4),
            ])->all(),
            'members' => $destinations->recipients($project->account_id),
            'canManage' => $user->can('create', [OnCallSchedule::class, $project]),
        ]);
    }
}
