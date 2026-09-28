<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\DeleteProject;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class DeleteProjectController
{
    /**
     * Delete the project once the person has typed its name exactly, after a recent password confirmation.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  DeleteProject  $delete
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, DeleteProject $delete): RedirectResponse
    {
        $request->validate(['confirm_name' => ['required', 'string']]);
        if (trim($request->string('confirm_name')->toString()) !== $project->name) {
            throw ValidationException::withMessages(['confirm_name' => __('Type the project name exactly as shown to confirm.')])->errorBag('deleteProject');
        }

        $delete->handle($user, $project);

        return to_route('dashboard')->with('status', __(':project was deleted.', ['project' => $project->name]));
    }
}
