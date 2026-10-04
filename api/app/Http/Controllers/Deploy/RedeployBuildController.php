<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\RedeployBuild;
use App\Models\Build;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class RedeployBuildController
{
    /**
     * Deploy a finished deploy's commit again and shows the new deploy.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Build  $build
     * @param  RedeployBuild  $redeploy
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Build $build, RedeployBuild $redeploy): JsonResponse
    {
        return response()->json(['redirect' => route('deploy.builds.show', [$project, $redeploy->handle($user, $build)->id], false)]);
    }
}
