<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\RollbackBuild;
use App\Models\Build;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class RollbackBuildController
{
    /**
     * Roll back to a deploy's release and shows the rollback.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Build  $build
     * @param  RollbackBuild  $rollback
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Build $build, RollbackBuild $rollback): JsonResponse
    {
        return response()->json(['redirect' => route('deploy.builds.show', [$project, $rollback->handle($user, $build)->id], false)]);
    }
}
