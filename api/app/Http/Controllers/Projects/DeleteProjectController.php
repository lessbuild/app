<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\DeleteProject;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectInfrastructureQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class DeleteProjectController
{
    /**
     * Delete the project once the person has typed its name exactly, after a recent password confirmation. Say how many
     * of its servers were destroyed with it, so the person sees the infrastructure went too.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  DeleteProject  $delete
     * @param  ProjectInfrastructureQuery  $infrastructure
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, DeleteProject $delete, ProjectInfrastructureQuery $infrastructure): JsonResponse
    {
        $request->validate(['confirm_name' => ['required', 'string']]);
        if (trim($request->string('confirm_name')->toString()) !== $project->name) {
            throw ValidationException::withMessages(['confirm_name' => __('Type the project name exactly as shown to confirm.')])->errorBag('deleteProject');
        }

        $name = $project->name;
        $servers = $infrastructure->exclusiveServers($project)->count();
        $delete->handle($user, $project);

        $message = $servers > 0
            ? trans_choice(':project and its server were deleted.|:project and its :count servers were deleted.', $servers, ['project' => $name, 'count' => $servers])
            : __(':project was deleted.', ['project' => $name]);

        return response()->json(['redirect' => route('dashboard', [], false), 'message' => $message]);
    }
}
