<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Http\Requests\Telemetry\SearchDependencyMapRequest;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Telemetry\DependencyMapQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowDependencyMapController
{
    /**
     * Show which services call which, built from trace spans (`?range=`, `?environment=`).
     *
     * @param  SearchDependencyMapRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  DependencyMapQuery  $dependencies
     * @return JsonResponse
     */
    public function __invoke(SearchDependencyMapRequest $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, DependencyMapQuery $dependencies): JsonResponse
    {
        $filters = $request->filters();
        $environment = isset($filters['environment']) ? (string) $filters['environment'] : null;
        abort_if($environment !== null && ! $project->environments()->whereKey($environment)->exists(), 404);

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'map' => $dependencies->handle($project, (string) $filters['range'], $environment),
            'filters' => $filters,
            'ranges' => array_map(fn (string $label): string => __($label), DependencyMapQuery::RANGES),
        ]);
    }
}
