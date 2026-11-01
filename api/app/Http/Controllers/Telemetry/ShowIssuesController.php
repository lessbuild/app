<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Data\Telemetry\IssueRow;
use App\Enums\IssueStatus;
use App\Http\Requests\Telemetry\SearchIssuesRequest;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Telemetry\IssueActivityQuery;
use App\Queries\Telemetry\IssuesQuery;
use App\Services\Monitoring\TelemetryRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowIssuesController
{
    /**
     * List the project's errors grouped into issues, with search, status, severity, environment, ownership and sort filters.
     *
     * @param  SearchIssuesRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  IssuesQuery  $search
     * @param  TelemetryRedactor  $redactor
     * @param  IssueActivityQuery  $activity
     * @return JsonResponse
     */
    public function __invoke(SearchIssuesRequest $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, IssuesQuery $search, TelemetryRedactor $redactor, IssueActivityQuery $activity): JsonResponse
    {
        $filters = $request->filters();
        $now = CarbonImmutable::now('UTC');
        $issuesQuery = $search->handle($project, $user, $filters)->with(['assignee', 'environment']);
        $matchingIssues = $search->handle($project, $user, [...$filters, 'status' => 'all']);
        $issues = $activity->prioritize($issuesQuery, $filters)
            ->paginate(20, ['*'], 'page', (int) ($filters['page'] ?? 1));

        $trends = $activity->trends(array_values(array_map(fn (Issue $issue): int => $issue->id, $issues->items())), environmentId: $filters['environment'] ?? null);

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'issues' => collect($issues->items())->map(fn (Issue $issue): array => [
                ...(array) IssueRow::from($issue->forceFill($redactor->redact($issue->only(['title', 'location'])))),
                'exceptionType' => is_string($issue->metadata['exception.type'] ?? null)
                    ? $redactor->redact(['type' => $issue->metadata['exception.type']])['type'] : null,
                'users' => $issue->affected_users,
                'firstSeenAt' => $issue->first_seen_at->toIso8601String(),
                'environment' => $issue->environment?->name,
                'eventsLastHour' => (int) ($issue->events_last_hour ?? 0),
                'eventsPreviousHour' => (int) ($issue->events_previous_hour ?? 0),
                'isNew' => $issue->first_seen_at->greaterThanOrEqualTo($now->subDay()),
                'spiking' => (int) ($issue->events_last_hour ?? 0) >= 5
                    && (int) ($issue->events_last_hour ?? 0) >= max(5, (int) ($issue->events_previous_hour ?? 0) * 2),
                'trend' => $trends[$issue->id] ?? [],
            ])->values(),
            'stats' => $activity->stats($matchingIssues, $filters['environment'] ?? null),
            'page' => $issues->currentPage(),
            'lastPage' => $issues->lastPage(),
            'filters' => $filters,
            'statuses' => array_map(fn (IssueStatus $status): array => ['value' => $status->value, 'label' => $status->label()], IssueStatus::cases()),
            'canUpdate' => $user->can('manageService', [$project, 'monitoring']),
        ]);
    }
}
