<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/projects/{project}/deploy/environments`. */
final class ShowDeployEnvironmentsController
{
    /**
     * Return the project's environments (not previews') with whether deploys are open, their runtime and strategy,
     * and how many variables, workers and resources each has.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview): JsonResponse
    {
        return response()->json([
            'overview' => $overview->handle($project, $user),
            'environments' => $project->environments()->whereDoesntHave('preview')->withCount(['variables', 'processes', 'resources'])->orderBy('name')->get()
                ->map(fn (Environment $environment): array => [
                    'id' => $environment->id,
                    'name' => $environment->name,
                    'blocked' => $environment->deploymentBlockReason() !== null,
                    'locked' => $environment->deployment_locked_at !== null,
                    'requiresApproval' => (bool) $environment->requires_deployment_approval,
                    'runtime' => $environment->runtime_type,
                    'runtimeVersion' => $environment->runtime_version,
                    'strategy' => $environment->deployment_strategy,
                    'variables' => (int) $environment->variables_count,
                    'processes' => (int) $environment->processes_count,
                    'resources' => (int) $environment->resources_count,
                ])->values(),
        ]);
    }
}
