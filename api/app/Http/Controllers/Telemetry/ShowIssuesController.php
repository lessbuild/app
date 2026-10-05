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
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowIssuesController
{
    /**
     * List the project's errors grouped into issues (`?q=`, `?status=`, `?ownership=any|mine|unassigned`, `?page=`).
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
        $issues = $search->handle($project, $user, $filters)->with('assignee')
            ->orderByDesc('last_seen_at')->orderByDesc('id')
            ->paginate(20, ['*'], 'page', (int) ($filters['page'] ?? 1));

        $trends = $activity->trends(array_values(array_map(fn (Issue $issue): int => $issue->id, $issues->items())));

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'issues' => collect($issues->items())->map(fn (Issue $issue): array => [
                ...(array) IssueRow::from($issue->forceFill($redactor->redact($issue->only(['title', 'location'])))),
                'users' => $issue->affected_users,
                'firstSeenAt' => $issue->first_seen_at->toIso8601String(),
                'trend' => $trends[$issue->id] ?? [],
            ])->values(),
            'stats' => $activity->stats($project),
            'page' => $issues->currentPage(),
            'lastPage' => $issues->lastPage(),
            'filters' => $filters,
            'statuses' => array_map(fn (IssueStatus $status): array => ['value' => $status->value, 'label' => $status->label()], IssueStatus::cases()),
        ]);
    }
}
