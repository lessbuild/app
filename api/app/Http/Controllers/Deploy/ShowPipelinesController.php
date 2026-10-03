<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Build;
use App\Models\DeployPipeline;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowPipelinesController
{
    /**
     * Show the project's deploy pipelines with their steps and recent runs, and the form to make one.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview): View
    {
        $pipelines = DeployPipeline::query()->where('project_id', $project->id)->with(['runs' => fn ($query) => $query->latest('id')->limit(5)])->orderBy('name')->get();
        $buildIds = $pipelines->flatMap(fn (DeployPipeline $pipeline) => $pipeline->runs->flatMap->build_ids)->unique()->all();

        return view('deploy.pipelines', [
            'overview' => $overview->handle($project, $user),
            'pipelines' => $pipelines,
            'repositories' => Repository::query()->where('project_id', $project->id)->with('environment')->orderBy('name')->get()->keyBy('id'),
            'builds' => Build::query()->whereKey($buildIds)->get(['id', 'status'])->keyBy('id'),
            'canManage' => $user->can('update', $project),
        ]);
    }
}
