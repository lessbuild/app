<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Data\Telemetry\EventRow;
use App\Data\Telemetry\IssueRow;
use App\Data\Telemetry\TraceRecord;
use App\Http\Requests\Telemetry\UpdateIssueRequest;
use App\Models\Issue;
use App\Models\IssueActivity;
use App\Models\IssueTracker;
use App\Models\Project;
use App\Models\TelemetryEvent;
use App\Models\User;
use App\Queries\Monitoring\ProjectIncidentsQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Telemetry\IssueActivityQuery;
use App\Services\Monitoring\TelemetryRedactor;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowIssueController
{
    /**
     * Show an issue: its details and recent occurrences (redacted), its activity, and filing a ticket for it.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Issue  $issue
     * @param  ProjectOverviewQuery  $overview
     * @param  TelemetryRedactor  $redactor
     * @param  ProjectIncidentsQuery  $members
     * @param  IssueActivityQuery  $activity
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Issue $issue, ProjectOverviewQuery $overview, TelemetryRedactor $redactor, ProjectIncidentsQuery $members, IssueActivityQuery $activity): JsonResponse
    {
        $events = TelemetryEvent::query()->where('issue_id', $issue->id)->summary()->with('environment')->orderByDesc('occurred_at')->orderByDesc('id')->limit(20)->get();
        $events->each(fn (TelemetryEvent $event): TelemetryEvent => $event->forceFill($redactor->redact($event->only(['name', 'route']))));
        $issue->forceFill($redactor->redact($issue->only(['title', 'location', 'details', 'fingerprint'])));

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'issue' => [
                ...(array) IssueRow::from($issue->loadMissing(['assignee', 'environment'])),
                'version' => $issue->state_version,
                'details' => $issue->details,
                'firstSeenAt' => $issue->first_seen_at->toIso8601String(),
                'snoozedUntil' => $issue->snoozed_until?->toIso8601String(),
                'assigneeId' => $issue->assignee_id,
                'environment' => $issue->environment?->name,
                'ticketUrl' => $issue->ticket_url,
                'ticketKey' => $issue->ticket_key,
                'open' => $issue->status->value === 'open',
                'users' => $issue->affected_users,
                'trend' => $activity->trends([$issue->id])[$issue->id],
                ...$activity->context($issue),
            ],
            'events' => $events->map(fn (TelemetryEvent $event): EventRow => EventRow::from(new TraceRecord($event)))->values(),
            'activities' => $issue->activities()->with('actor')->latest('id')->limit(50)->get()->map(fn (IssueActivity $activity): array => [
                'id' => $activity->id, 'label' => __(ucfirst(str_replace('_', ' ', $activity->action))), 'actor' => $activity->actor?->name, 'note' => $activity->note, 'at' => $activity->created_at?->toIso8601String(),
            ])->values(),
            'assignees' => array_map(fn (User $member): array => ['value' => (string) $member->id, 'label' => $member->name], $members->assignees($project)),
            'snoozeOptions' => array_map(fn (int $minutes, string $label): array => ['value' => (string) $minutes, 'label' => __($label)], array_keys(UpdateIssueRequest::SNOOZE_MINUTES), UpdateIssueRequest::SNOOZE_MINUTES),
            'trackers' => IssueTracker::query()->where('project_id', $project->id)->orderBy('name')->get()
                ->map(fn (IssueTracker $tracker): array => ['value' => (string) $tracker->id, 'label' => $tracker->name.' · '.$tracker->destination()])->values(),
            'canUpdate' => $user->can('manageService', [$project, 'monitoring']),
        ]);
    }
}
