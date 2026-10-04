<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\UpdateBuildCache;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UpdateBuildCacheController
{
    /**
     * Save the build cache setting, or clear the cache, and return to the repository's settings.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Repository  $repository
     * @param  UpdateBuildCache  $update
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Repository $repository, UpdateBuildCache $update): JsonResponse
    {
        $request->validate(['build_cache_enabled' => ['required', 'boolean'], 'clear' => ['sometimes', 'boolean']]);
        $clear = $request->boolean('clear');
        $update->handle($user, $repository, $request->boolean('build_cache_enabled'), $clear);

        return response()->json(['redirect' => route('deploy.repositories.show', [$project, $repository->id, 'tab' => 'settings'], false), 'message' => $clear ? __('Build cache cleared. The next deploy starts fresh.') : __('Build cache setting saved.')]);
    }
}
