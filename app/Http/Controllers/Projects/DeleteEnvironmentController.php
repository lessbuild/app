<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\DeleteEnvironment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteEnvironmentController
{
    /**
     * Deletes one of the project's environments.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  string  $environment
     * @param  DeleteEnvironment  $delete
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, string $environment, DeleteEnvironment $delete): RedirectResponse
    {
        $target = $project->environments()->findOrFail($environment);
        $delete->handle($user, $target);

        return to_route('projects.settings', $project)->with('status', __(':environment removed.', ['environment' => $target->name]));
    }
}
