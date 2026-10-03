<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\DismissChecklist;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class DismissChecklistController
{
    /**
     * Hide the getting-started checklist.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  DismissChecklist  $dismiss
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, DismissChecklist $dismiss): JsonResponse
    {
        $dismiss->handle($user, $project);

        return response()->json(['redirect' => route('projects.show', $project, false)]);
    }
}
