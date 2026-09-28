<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\UpdateProject;
use App\Http\Requests\Projects\ProjectRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class UpdateProjectController
{
    /**
     * Saves the project's name and description.
     *
     * @param  ProjectRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  UpdateProject  $update
     * @return RedirectResponse
     */
    public function __invoke(ProjectRequest $request, #[CurrentUser] User $user, Project $project, UpdateProject $update): RedirectResponse
    {
        $update->handle($user, $project, $request->toDetails());

        return to_route('projects.settings', $project)->with('status', __('Project saved.'));
    }
}
