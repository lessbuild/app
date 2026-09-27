<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Enums\IssueStatus;
use App\Http\Requests\Telemetry\SearchIssuesRequest;
use App\Models\Issue;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Telemetry\IssuesQuery;
use App\Services\Monitoring\TelemetryRedactor;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

/** Errors grouped by fingerprint, newest activity first. */
final class ShowIssuesController
{
    public function __invoke(SearchIssuesRequest $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, IssuesQuery $search, TelemetryRedactor $redactor): View
    {
        $filters = $request->filters();
        $issues = $search->handle($project, $user, $filters)
            ->with('assignee')
            ->orderByDesc('last_seen_at')->orderByDesc('id')
            ->paginate(20, ['*'], 'page', (int) ($filters['page'] ?? 1))
            ->appends($request->safe()->except(['page']));
        $issues->getCollection()->each(fn (Issue $issue): Issue => $issue->forceFill($redactor->redact($issue->only(['title', 'location']))));

        return view('telemetry.issues', [
            'overview' => $overview->handle($project, $user),
            'issues' => $issues,
            'filters' => $filters,
            'statuses' => IssueStatus::cases(),
        ]);
    }
}
