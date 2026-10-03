<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\DeleteEnvironment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class DeleteEnvironmentController
{
    /**
     * Delete one of the project's environments.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  string  $environment
     * @param  DeleteEnvironment  $delete
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, string $environment, DeleteEnvironment $delete): JsonResponse
    {
        $target = $project->environments()->findOrFail($environment);
        $delete->handle($user, $target);

        return response()->json(['redirect' => route('projects.settings', $project, false), 'message' => __(':environment removed.', ['environment' => $target->name])]);
    }
}
