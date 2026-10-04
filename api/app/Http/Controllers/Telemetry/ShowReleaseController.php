<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Http\Requests\Telemetry\SearchReleasesRequest;
use App\Models\Deployment;
use App\Models\Issue;
use App\Models\Project;
use App\Models\Release;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Telemetry\ReleaseMetricsQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowReleaseController
{
    /**
     * Show a release: its requests, errors and response times (`?range=`, `?environment=`), its issues and where it
     * was deployed.
     *
     * @param  SearchReleasesRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Release  $release
     * @param  ProjectOverviewQuery  $overview
     * @param  ReleaseMetricsQuery  $metrics
     * @return JsonResponse
     */
    public function __invoke(SearchReleasesRequest $request, #[CurrentUser] User $user, Project $project, Release $release, ProjectOverviewQuery $overview, ReleaseMetricsQuery $metrics): JsonResponse
    {
        $filters = $request->filters();
        $environment = isset($filters['environment']) ? (string) $filters['environment'] : null;
        abort_if($environment !== null && ! $project->environments()->whereKey($environment)->exists(), 404);
        [$from, $until] = $metrics->window((string) $filters['range']);
        $events = $metrics->events($project, $release, $environment, $from, $until);

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'release' => ['id' => $release->id, 'version' => $release->version, 'service' => $release->serviceLabel()],
            'filters' => $filters,
            'metrics' => $metrics->summarize($events),
            'issues' => Issue::query()->whereBelongsTo($project)->whereIn('id', (clone $events)->where('type', 'exception')->select('issue_id'))->latest('id')->limit(20)->get()
                ->map(fn (Issue $issue): array => ['id' => $issue->id, 'title' => $issue->title, 'statusLabel' => $issue->status->label(), 'statusTone' => $issue->status->tone()])->values(),
            'deployments' => $release->deployments()->with(['environment', 'actor'])
                ->when($environment !== null, fn ($query) => $query->where('environment_id', $environment))
                ->latest('deployed_at')->latest('id')->limit(20)->get()
                ->map(fn (Deployment $deployment): array => ['id' => $deployment->id, 'environment' => $deployment->environment->name, 'actor' => $deployment->actor?->name, 'deployedAt' => $deployment->deployed_at->toIso8601String()])->values(),
            'ranges' => array_map(fn (string $label): string => __($label), SearchReleasesRequest::RANGES),
        ]);
    }
}
