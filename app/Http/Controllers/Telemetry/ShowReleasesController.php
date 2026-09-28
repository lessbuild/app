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
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/** Versions seen in telemetry, and the latest deployments. */
final class ShowReleasesController
{
    /**
     * Show the project's releases, searchable by version or service, and its latest deployments.
     *
     * @param  SearchReleasesRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @return View
     */
    public function __invoke(SearchReleasesRequest $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview): View
    {
        $filters = $request->filters();
        $query = Release::query()->whereBelongsTo($project);
        if (isset($filters['q'])) {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $filters['q']).'%';
            $query->where(fn ($search) => $search->whereRaw("version LIKE ? ESCAPE '!'", [$pattern])
                ->whereRaw("service LIKE ? ESCAPE '!'", [$pattern], 'or'));
        }

        return view('telemetry.releases', [
            'overview' => $overview->handle($project, $user),
            'releases' => $query->latest('created_at')->latest('id')->paginate(25, ['*'], 'page', (int) ($filters['page'] ?? 1))->appends($request->safe()->except('page')),
            'deployments' => Deployment::query()->whereIn('environment_id', $project->environments()->select('id'))
                ->with(['release', 'environment', 'actor'])->latest('deployed_at')->latest('id')->limit(10)->get(),
            'filters' => $filters,
            'deploymentId' => (string) Str::uuid(),
            'canRecord' => $user->can('manageService', [$project, 'monitoring']),
        ]);
    }
}
