<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\ArchiveProject;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `PUT /api/app/projects/{project}/archive`. */
final class ArchiveProjectController
{
    /**
     * Archive the project or restore it, for people who can change it.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ArchiveProject  $archive
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, ArchiveProject $archive): JsonResponse
    {
        $archived = (bool) $request->validate(['archived' => ['required', 'boolean']])['archived'];
        $archive->handle($user, $project, $archived);

        return response()->json([
            'redirect' => $archived ? '/dashboard?projects=archived' : '/projects/'.$project->id,
            'message' => $archived ? __('Project archived. Everything in it is kept; restore it whenever you like.') : __('Project restored.'),
        ]);
    }
}
