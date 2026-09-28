<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Http\Requests\Telemetry\UpdateIssueRequest;
use App\Models\Issue;
use App\Models\Project;
use App\Models\TelemetryEvent;
use App\Models\User;
use App\Queries\Monitoring\ProjectIncidentsQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Monitoring\TelemetryRedactor;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowIssueController
{
    /**
     * An issue's page: its latest occurrences and timeline, redacted, and who it can be assigned to.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Issue  $issue
     * @param  ProjectOverviewQuery  $overview
     * @param  TelemetryRedactor  $redactor
     * @param  ProjectIncidentsQuery  $members
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Issue $issue, ProjectOverviewQuery $overview, TelemetryRedactor $redactor, ProjectIncidentsQuery $members): View
    {
        $events = TelemetryEvent::query()->where('issue_id', $issue->id)->summary()->with('environment')
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(20)->get();
        $events->each(fn (TelemetryEvent $event): TelemetryEvent => $event->forceFill($redactor->redact($event->only(['name', 'route']))));
        $activities = $issue->activities()->with('actor')->latest('id')->limit(50)->get();
        $issue->forceFill($redactor->redact($issue->only(['title', 'location', 'details', 'fingerprint'])));

        return view('telemetry.issue', [
            'overview' => $overview->handle($project, $user),
            'issue' => $issue,
            'events' => $events,
            'activities' => $activities,
            'assignees' => $members->assignees($project),
            'canUpdate' => $user->can('manageService', [$project, 'monitoring']),
            'snoozeOptions' => UpdateIssueRequest::SNOOZE_MINUTES,
        ]);
    }
}
