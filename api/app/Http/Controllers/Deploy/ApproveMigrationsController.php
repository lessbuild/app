<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\ApproveMigrations;
use App\Models\Build;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ApproveMigrationsController
{
    /**
     * Approve the migrations a deploy stopped for and open the new deploy.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Build  $build
     * @param  ApproveMigrations  $approve
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Build $build, ApproveMigrations $approve): JsonResponse
    {
        $deploy = $approve->handle($user, $build);

        return response()->json(['redirect' => route('deploy.builds.show', [$project, $deploy->id], false), 'message' => __('Migrations approved. Deploying again.')]);
    }
}
