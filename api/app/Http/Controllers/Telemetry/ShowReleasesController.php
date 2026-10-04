<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Http\Requests\Telemetry\SearchReleasesRequest;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Release;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
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
     * @return JsonResponse
     */
    public function __invoke(SearchReleasesRequest $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview): JsonResponse
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
            'releases' => collect($releases->items())->map(fn (Release $release): array => [
                'id' => $release->id, 'version' => $release->version, 'service' => $release->serviceLabel(), 'lastSeenAt' => $release->last_seen_at?->toIso8601String(),
            ])->values(),
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
