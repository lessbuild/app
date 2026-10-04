<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\CancelScheduledDeploy;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class CancelScheduledDeployController
{
    /**
     * Cancel a booked deploy that hasn't run yet and return to the repository.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Repository  $repository
     * @param  int  $scheduled
     * @param  CancelScheduledDeploy  $cancel
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Repository $repository, int $scheduled, CancelScheduledDeploy $cancel): JsonResponse
    {
        $cancel->handle($user, $repository, $scheduled);

        return response()->json(['redirect' => route('deploy.repositories.show', [$project, $repository->id], false), 'message' => __('Booked deploy cancelled.')]);
    }
}
