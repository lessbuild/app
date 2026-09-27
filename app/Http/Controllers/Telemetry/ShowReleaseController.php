<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Http\Requests\Telemetry\SearchReleasesRequest;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Release;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Telemetry\ReleaseMetricsQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowReleaseController
{
    /**
     * A release's metrics over a range, optionally in one of the project's environments.
     */
    public function __invoke(SearchReleasesRequest $request, #[CurrentUser] User $user, Project $project, Release $release, ProjectOverviewQuery $overview, ReleaseMetricsQuery $metrics): View
    {
        $filters = $request->filters();
        $environment = isset($filters['environment']) ? (string) $filters['environment'] : null;
        abort_if($environment !== null && ! $project->environments()->whereKey($environment)->exists(), 404);
        [$from, $until] = $metrics->window((string) $filters['range']);
        $events = $metrics->events($project, $release, $environment, $from, $until);

        return view('telemetry.release', [
            'overview' => $overview->handle($project, $user),
            'release' => $release,
            'filters' => $filters,
            'from' => $from,
            'until' => $until,
            'metrics' => $metrics->summarize($events),
            'issues' => Issue::query()->whereBelongsTo($project)->whereIn('id', (clone $events)->where('type', 'exception')->select('issue_id'))
                ->latest('id')->limit(20)->get(),
            'deployments' => $release->deployments()->with(['environment', 'actor'])
                ->when($environment !== null, fn ($query) => $query->where('environment_id', $environment))
                ->latest('deployed_at')->latest('id')->limit(20)->get(),
            'rangeOptions' => SearchReleasesRequest::RANGES,
        ]);
    }
}
