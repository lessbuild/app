<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Actions\Projects\CreateProject;
use App\Actions\Projects\SyncProjectServices;
use App\Http\Requests\Projects\ProjectRequest;
use App\Models\User;
use App\Platform\ServiceRegistry;
use App\Support\Api\ResourceJson;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

final class StoreProjectController
{
    /**
     * Create a project with the services it should have (`POST /api/v2/projects`).
     *
     * @param  ProjectRequest  $request
     * @param  User  $user
     * @param  CreateProject  $create
     * @param  SyncProjectServices  $services
     * @param  ServiceRegistry  $registry
     * @return JsonResponse
     */
    public function __invoke(ProjectRequest $request, #[CurrentUser] User $user, CreateProject $create, SyncProjectServices $services, ServiceRegistry $registry): JsonResponse
    {
        $wanted = $request->validate(['services' => ['sometimes', 'array'], 'services.*' => ['string', Rule::in($registry->keys())]])['services'] ?? [];
        $project = $create->handle($user, ResourceJson::account($request), $request->toDetails());
        $services->handle($user, $project, array_values(array_unique($wanted)));

        return response()->json(['data' => ResourceJson::project($project->refresh())], 201);
    }
}
