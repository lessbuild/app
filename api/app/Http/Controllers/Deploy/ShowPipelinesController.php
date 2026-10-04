<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Build;
use App\Models\DeployPipeline;
use App\Models\DeployPipelineRun;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/projects/{project}/deploy/pipelines`. */
final class ShowPipelinesController
{
    /**
     * Return the project's pipelines with their steps and recent runs, and the repositories a pipeline can chain.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview): JsonResponse
    {
        $pipelines = DeployPipeline::query()->where('project_id', $project->id)->with(['runs' => fn ($query) => $query->latest('id')->limit(5)])->orderBy('name')->get();
        $repositories = Repository::query()->where('project_id', $project->id)->with('environment')->orderBy('name')->get()->keyBy('id');
        $builds = Build::query()->whereKey($pipelines->flatMap(fn (DeployPipeline $pipeline) => $pipeline->runs->flatMap->build_ids)->unique()->all())->pluck('status', 'id');
        $describe = fn (int $id): array => ['id' => $id, 'name' => $repositories->get($id)?->name, 'environment' => $repositories->get($id)?->environment?->name];

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'pipelines' => $pipelines->map(fn (DeployPipeline $pipeline): array => [
                'id' => $pipeline->id,
                'name' => $pipeline->name,
                'steps' => array_map($describe, $pipeline->repository_ids),
                'runs' => $pipeline->runs->map(fn (DeployPipelineRun $run): array => [
                    'id' => $run->id,
                    'status' => $run->status,
                    'createdAt' => $run->created_at?->toIso8601String(),
                    'builds' => array_map(fn (int $id): array => ['id' => $id, 'status' => $builds->get($id)], $run->build_ids),
                    'failure' => $run->failure,
                ])->values(),
            ])->values(),
            'repositories' => $repositories->map(fn (Repository $repository): array => ['value' => (string) $repository->id, 'label' => trim($repository->name.' · '.($repository->environment->name ?? ''), ' ·')])->values(),
            'canManage' => $user->can('update', $project),
        ]);
    }
}
