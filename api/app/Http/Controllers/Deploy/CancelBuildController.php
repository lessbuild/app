<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\CancelBuild;
use App\Models\Build;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class CancelBuildController
{
    /**
     * Cancel a deploy that hasn't finished.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Build  $build
     * @param  CancelBuild  $cancel
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Build $build, CancelBuild $cancel): JsonResponse
    {
        $cancel->handle($user, $build);

        return response()->json(['redirect' => route('deploy.builds.show', [$project, $build->id], false), 'message' => __('Deploy canceled.')]);
    }
}
