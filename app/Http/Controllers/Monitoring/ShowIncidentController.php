<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\Incident;
use App\Models\Project;
use App\Models\StatusPage;
use App\Models\StatusUpdate;
use App\Models\User;
use App\Queries\Monitoring\ProjectIncidentsQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Monitoring\IncidentSummary;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowIncidentController
{
    /**
     * Show an incident's page: its timeline, who it can be assigned to, and a post-mortem draft for when none has
     * been written yet.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Incident  $incident
     * @param  ProjectOverviewQuery  $overview
     * @param  ProjectIncidentsQuery  $incidents
     * @param  IncidentSummary  $summary
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Incident $incident, ProjectOverviewQuery $overview, ProjectIncidentsQuery $incidents, IncidentSummary $summary): View
    {
        return view('monitoring.incident', [
            'overview' => $overview->handle($project, $user),
            'incident' => $incident,
            'activities' => $incident->activities()->with('actor')->latest('id')->limit(100)->get(),
            'assignees' => $incidents->assignees($project),
            'canRespond' => $user->can('update', $incident),
            'statusPages' => StatusPage::query()->where('account_id', $incident->account_id)->orderBy('name')->get(['id', 'name', 'published']),
            'draft' => $incident->postmortem === null ? $summary->draft($incident) : [],
            'publishedReport' => $incident->postmortem_status_update_id === null ? null : StatusUpdate::query()->with('statusPage')->find($incident->postmortem_status_update_id),
        ]);
    }
}
