<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Actions\Projects\DeleteProject;
use App\Models\Project;
use App\Models\User;
use App\Support\Api\ResourceJson;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class DestroyProjectController
{
    /**
     * Delete a project with its environments (`DELETE /api/v2/projects/{id}`).
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  string  $projectId
     * @param  DeleteProject  $delete
     * @return Response
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, string $projectId, DeleteProject $delete): Response
    {
        $project = Project::query()->where('account_id', ResourceJson::account($request)->id)->findOrFail($projectId);
        abort_unless($user->can('view', $project), 404);
        $delete->handle($user, $project);

        return response()->noContent();
    }
}
