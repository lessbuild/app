<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Build;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Queries\Deploy\DeployActivityQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/projects/{project}/deploy`. */
final class ShowRepositoriesController
{
    /**
     * Return the project's repositories (not previews'), where each deploys to, its latest deploy and whether pushes and
     * pull requests deploy, with how delivery has gone lately: stats, deploys waiting for approval and recent deploys.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  DeployActivityQuery  $activity
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, DeployActivityQuery $activity): JsonResponse
    {
        $repositories = Repository::query()->where('project_id', $project->id)->whereDoesntHave('preview')->with(['website', 'environment'])->orderBy('name')->get();
        $latest = Build::query()->whereIn('id', Build::query()->whereIn('repository_id', $repositories->modelKeys())->selectRaw('MAX(id)')->groupBy('repository_id'))->get()->keyBy('repository_id');

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'repositories' => $repositories->map(fn (Repository $repository): array => [
                'id' => $repository->id,
                'name' => $repository->name,
                'url' => $repository->url,
                'branch' => $repository->branch,
                'website' => $repository->website->name,
                'environment' => $repository->environment?->name,
                'latestBuild' => ($build = $latest->get($repository->id)) instanceof Build ? ['id' => $build->id, 'status' => $build->status, 'createdAt' => $build->created_at?->toIso8601String()] : null,
                'pushDeploys' => $repository->webhook_enabled,
                'previews' => $repository->previews_enabled,
            ])->values(),
            ...$activity->handle($project),
            'canCreate' => $user->can('create', [Repository::class, $project]),
        ]);
    }
}
