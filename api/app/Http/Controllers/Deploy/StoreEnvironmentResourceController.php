<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\SaveEnvironmentResource;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StoreEnvironmentResourceController
{
    /**
     * Add or changes a resource (database, cache or object storage) on an environment.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  SaveEnvironmentResource  $save
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Environment $environment, SaveEnvironmentResource $save): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', 'regex:/\A[a-z0-9][a-z0-9-]*\z/'],
            'type' => ['required', 'in:mysql,postgresql,redis,valkey,object_storage'],
            'variables' => ['nullable', 'string', 'max:20000'],
        ]);
        $save->handle($user, $environment, ['name' => $data['name'], 'type' => $data['type'], 'is_managed' => $request->boolean('is_managed'), 'variables' => $data['variables'] ?? null]);

        return response()->json(['redirect' => route('deploy.environments.show', [$project, $environment, 'tab' => 'resources'], false), 'message' => __('Resource saved.')]);
    }
}
