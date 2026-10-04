<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Queries\Deploy\DeployEnvironmentPageQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/projects/{project}/deploy/environments/{environment}`. */
final class ShowDeployEnvironmentController
{
    /**
     * Return an environment's deploy settings, tab by tab.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  ProjectOverviewQuery  $overview
     * @param  DeployEnvironmentPageQuery  $page
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Environment $environment, ProjectOverviewQuery $overview, DeployEnvironmentPageQuery $page): JsonResponse
    {
        return response()->json(['overview' => $overview->handle($project, $user), ...$page->handle($environment, $user)]);
    }
}
