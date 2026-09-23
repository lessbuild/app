<?php

namespace App\Modules\Monitor\Http\Controllers;

use App\Modules\Monitor\Data\Telemetry\IssueStatus;
use App\Modules\Monitor\Http\Requests\SearchIssuesRequest;
use App\Modules\Monitor\Http\Requests\UpdateIssueRequest;
use App\Modules\Monitor\Models\Issue;
use App\Modules\Monitor\Models\IssueActivity;
use App\Modules\Monitor\Models\TelemetryEvent;
use App\Modules\Monitor\Services\ChangeIssue;
use App\Modules\Monitor\Services\CurrentWorkspace;
use App\Modules\Monitor\Services\SearchIssues;
use App\Modules\Monitor\Services\Telemetry\TelemetryRedactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class IssueController extends Controller
{
    public function index(SearchIssuesRequest $request, CurrentWorkspace $currentWorkspace, SearchIssues $search, TelemetryRedactor $redactor): Response
    {
        $workspace = $currentWorkspace->get();
        $filters = $request->filters();
        $applications = $workspace->applications()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();

        if (isset($filters['application'])) {
            abort_unless($applications->contains('id', (int) $filters['application']), 404);
        }

        $issues = $search->query($workspace, $request->user(), $filters)
            ->with(['application:id,name', 'assignee:id,name'])
            ->select(['id', 'application_id', 'assignee_id', 'type', 'severity', 'status', 'title', 'location', 'occurrences', 'last_seen_at'])
            ->orderByDesc('last_seen_at')->orderByDesc('id')->paginate(20, ['*'], 'page', (int) ($filters['page'] ?? 1))
            ->appends($request->safe()->except(['page', 'events_page', 'activity_page']));
        $issues->each(fn (Issue $issue): Issue => $issue->forceFill($redactor->redact($issue->only(['title', 'location']))));
        $totals = Issue::forWorkspace($workspace)->toBase()
            ->selectRaw("status, count(*) as total, sum(case when severity = 'critical' then 1 else 0 end) as critical")
            ->groupBy('status')->get()->keyBy('status');

        return response()->view('monitor::issues.index', [
            'issues' => $issues,
            'filters' => $filters,
            'applications' => $applications,
            'statuses' => IssueStatus::cases(),
            'totals' => $totals,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(SearchIssuesRequest $request, Issue $issue, CurrentWorkspace $currentWorkspace, TelemetryRedactor $redactor): Response
    {
        $workspace = $currentWorkspace->get();
        $filters = $request->validated();
        $issue->load(['application', 'environment:id,name', 'assignee:id,name']);
        $events = TelemetryEvent::forWorkspace($workspace)->where('issue_id', $issue->id)->summary()
            ->with(['environment:id,application_id,name', 'environment.application:id,name'])
            ->orderByDesc('occurred_at')->orderByDesc('id')->paginate(20, ['*'], 'events_page', (int) ($filters['events_page'] ?? 1))
            ->appends(array_intersect_key($filters, ['activity_page' => true]));
        $events->each(fn (TelemetryEvent $event): TelemetryEvent => $event->forceFill($redactor->redact($event->only(['name', 'route']))));
        $activities = $issue->activities()->with('actor:id,name')->latest('id')
            ->paginate(20, ['*'], 'activity_page', (int) ($filters['activity_page'] ?? 1))
            ->appends(array_intersect_key($filters, ['events_page' => true]));
        $activities->each(fn (IssueActivity $activity): IssueActivity => $activity->forceFill($redactor->redact(['note' => $activity->note])));
        $issue->forceFill($redactor->redact($issue->only(['title', 'location', 'details', 'fingerprint'])));
        $metadata = $redactor->redact(['attributes' => $issue->metadata ?? []])['attributes'];

        return response()->view('monitor::issues.show', [
            'issue' => $issue,
            'events' => $events,
            'activities' => $activities,
            'metadataJson' => json_encode($metadata ?: new \stdClass, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            'assignees' => $workspace->members()->wherePivotIn('role', ['owner', 'admin', 'member'])->select(['users.id', 'users.name'])->orderBy('users.name')->orderBy('users.id')->get(),
            'canUpdate' => Gate::allows('update', $issue),
            'snoozeOptions' => UpdateIssueRequest::SNOOZE_MINUTES,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function update(UpdateIssueRequest $request, Issue $issue, CurrentWorkspace $currentWorkspace, ChangeIssue $changes): RedirectResponse
    {
        $changes->update($issue, $currentWorkspace->get(), $request->user(), $request->validated());

        return to_route('monitor.issues.show', $issue)->with('status', 'Issue updated.');
    }
}
