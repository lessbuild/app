<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Http\Requests\Telemetry\SearchReleasesRequest;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Release;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Telemetry\ReleaseMetricsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

final class ShowReleasesController
{
    /**
     * List the versions the project's apps report (`?q=`, `?page=`) and its latest deployments.
     *
     * @param  SearchReleasesRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ReleaseMetricsQuery  $metrics
     * @return JsonResponse
     */
    public function __invoke(SearchReleasesRequest $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ReleaseMetricsQuery $metrics): JsonResponse
    {
        $filters = $request->filters();
        $query = Release::query()->whereBelongsTo($project);
        if (isset($filters['q'])) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $filters['q']).'%';
            $query->where(fn ($search) => $search->whereRaw("version LIKE ? ESCAPE '!'", [$pattern])->whereRaw("service LIKE ? ESCAPE '!'", [$pattern], 'or'));
        }
        $releases = $query->latest('created_at')->latest('id')->paginate(25, ['*'], 'page', (int) ($filters['page'] ?? 1));

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'releases' => collect($releases->items())->map(function (Release $release) use ($project, $metrics): array {
                // How the release has done since it was first seen (or over the last 30 days): its error rate, average
                // response time and the issues it raised, with the deployment that shipped it.
                $summary = $metrics->summarize($metrics->events($project, $release, null, $release->first_seen_at ?? CarbonImmutable::now('UTC')->subDays(30), CarbonImmutable::now('UTC')));
                $deployment = $release->deployments()->with('actor')->oldest('deployed_at')->first();

                return [
                    'id' => $release->id, 'version' => $release->version, 'service' => $release->serviceLabel(), 'lastSeenAt' => $release->last_seen_at?->toIso8601String(),
                    'wentLiveAt' => ($deployment->deployed_at ?? $release->first_seen_at)?->toIso8601String(),
                    'by' => $deployment?->actor?->name,
                    'issues' => $summary['issues'],
                    'errorRate' => $summary['errorRate'] === null ? null : round($summary['errorRate'], 2),
                    'averageMs' => $summary['averageDuration'] === null ? null : (int) round($summary['averageDuration']),
                ];
            })->values(),
            'page' => $releases->currentPage(),
            'lastPage' => $releases->lastPage(),
            'deployments' => Deployment::query()->whereIn('environment_id', $project->environments()->select('id'))
                ->with(['release', 'environment', 'actor'])->latest('deployed_at')->latest('id')->limit(10)->get()
                ->map(fn (Deployment $deployment): array => [
                    'id' => $deployment->id, 'version' => $deployment->release->version, 'environment' => $deployment->environment->name,
                    'actor' => $deployment->actor?->name, 'deployedAt' => $deployment->deployed_at->toIso8601String(),
                ])->values(),
            'filters' => $filters,
            // A fresh idempotency key for the "record a deployment" form.
            'deploymentId' => (string) Str::uuid(),
            'canRecord' => $user->can('manageService', [$project, 'monitoring']),
        ]);
    }
}
