<?php

declare(strict_types=1);

namespace App\Http\Controllers\Telemetry;

use App\Http\Requests\Telemetry\SearchDependencyMapRequest;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Telemetry\DependencyMapQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

/** Which services call which, from trace spans. */
final class ShowDependencyMapController
{
    /**
     * The dependency map for a range, optionally for one of the project's environments.
     *
     * @param  SearchDependencyMapRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  DependencyMapQuery  $dependencies
     * @return View
     */
    public function __invoke(SearchDependencyMapRequest $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, DependencyMapQuery $dependencies): View
    {
        $filters = $request->filters();
        $environment = isset($filters['environment']) ? (string) $filters['environment'] : null;
        abort_if($environment !== null && ! $project->environments()->whereKey($environment)->exists(), 404);

        return view('telemetry.dependencies', [
            'overview' => $overview->handle($project, $user),
            'map' => $dependencies->handle($project, (string) $filters['range'], $environment),
            'filters' => $filters,
            'rangeOptions' => DependencyMapQuery::RANGES,
        ]);
    }
}
