<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\PinProject;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `PUT /api/app/projects/{project}/pin`. */
final class PinProjectController
{
    /**
     * Pin the project to the top of the person's projects list, or unpin it.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  PinProject  $pin
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, PinProject $pin): JsonResponse
    {
        $pinned = (bool) $request->validate(['pinned' => ['required', 'boolean']])['pinned'];
        $pin->handle($user, $project, $pinned);

        return response()->json(['pinned' => $pinned, 'message' => $pinned ? __('Pinned to the top of your projects.') : __('Unpinned.')]);
    }
}
