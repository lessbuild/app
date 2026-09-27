<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\ProjectIncidentsQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowIncidentController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $incident, ProjectOverviewQuery $overview, ProjectIncidentsQuery $incidents): View
    {
        $target = $incidents->find($project, $incident);

        return view('monitoring.incident', [
            'overview' => $overview->handle($project, $user),
            'incident' => $target,
            'activities' => $target->activities()->with('actor')->latest('id')->limit(100)->get(),
            'assignees' => $incidents->assignees($project),
            'canRespond' => $user->can('manageService', [$project, 'monitoring']),
        ]);
    }
}
