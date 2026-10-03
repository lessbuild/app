<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Actions\Projects\SyncProjectServices;
use App\Actions\Projects\UpdateProject;
use App\Http\Requests\Projects\ProjectRequest;
use App\Models\Project;
use App\Models\User;
use App\Platform\ServiceRegistry;
use App\Support\Api\ResourceJson;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

final class UpdateProjectController
{
    /**
     * Change a project's name, description and, when given, its services (`PATCH /api/v2/projects/{id}`).
     *
     * @param  ProjectRequest  $request
     * @param  User  $user
     * @param  string  $projectId
     * @param  UpdateProject  $update
     * @param  SyncProjectServices  $services
     * @param  ServiceRegistry  $registry
     * @return JsonResponse
     */
    public function __invoke(ProjectRequest $request, #[CurrentUser] User $user, string $projectId, UpdateProject $update, SyncProjectServices $services, ServiceRegistry $registry): JsonResponse
    {
        $project = Project::query()->where('account_id', ResourceJson::account($request)->id)->findOrFail($projectId);
        abort_unless($user->can('view', $project), 404);
        $wanted = $request->validate(['services' => ['sometimes', 'array'], 'services.*' => ['string', Rule::in($registry->keys())]])['services'] ?? null;
        $project = $update->handle($user, $project, $request->toDetails());
        if (is_array($wanted)) {
            $services->handle($user, $project, array_values(array_unique($wanted)));
        }

        return response()->json(['data' => ResourceJson::project($project->refresh())]);
    }
}
