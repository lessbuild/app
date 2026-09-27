<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\Incident;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\ProjectIncidentsQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowIncidentController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, Incident $incident, ProjectOverviewQuery $overview, ProjectIncidentsQuery $incidents): View
    {

        return view('monitoring.incident', [
            'overview' => $overview->handle($project, $user),
            'incident' => $incident,
            'activities' => $incident->activities()->with('actor')->latest('id')->limit(100)->get(),
            'assignees' => $incidents->assignees($project),
            'canRespond' => $user->can('manageService', [$project, 'monitoring']),
        ]);
    }
}
