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
    public function __invoke(#[CurrentUser] User $user, Project $project, string $issue, ProjectOverviewQuery $overview, TelemetryRedactor $redactor, ProjectIncidentsQuery $members): View
    {
        $record = Issue::query()->whereBelongsTo($project)->with(['environment', 'assignee'])->findOrFail((int) $issue);
        $events = TelemetryEvent::query()->where('issue_id', $record->id)->summary()->with('environment')
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(20)->get();
        $events->each(fn (TelemetryEvent $event): TelemetryEvent => $event->forceFill($redactor->redact($event->only(['name', 'route']))));
        $activities = $record->activities()->with('actor')->latest('id')->limit(50)->get();
        $record->forceFill($redactor->redact($record->only(['title', 'location', 'details', 'fingerprint'])));

        return view('telemetry.issue', [
            'overview' => $overview->handle($project, $user),
            'issue' => $record,
            'events' => $events,
            'activities' => $activities,
            'assignees' => $members->assignees($project),
            'canUpdate' => $user->can('manageService', [$project, 'monitoring']),
            'snoozeOptions' => UpdateIssueRequest::SNOOZE_MINUTES,
        ]);
    }
}
