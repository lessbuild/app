<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Build;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowRepositoriesController
{
    /**
     * Show the project's repositories with each one's latest deploy. Previews' own repositories are on the previews page.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview): View
    {
        $repositories = Repository::query()->where('project_id', $project->id)->whereDoesntHave('preview')->with(['website', 'environment'])->orderBy('name')->get();

        return view('deploy.repositories', [
            'overview' => $overview->handle($project, $user),
            'repositories' => $repositories,
            'latest' => Build::query()->whereIn('id', Build::query()->whereIn('repository_id', $repositories->modelKeys())->selectRaw('MAX(id)')->groupBy('repository_id'))->get()->keyBy('repository_id'),
            'canCreate' => $user->can('create', [Repository::class, $project]),
        ]);
    }
}
