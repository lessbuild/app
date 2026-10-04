<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\DeployRepository;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StoreBuildController
{
    /**
     * Start a deploy, of a given commit or the branch's latest.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Repository  $repository
     * @param  DeployRepository  $deploy
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Repository $repository, DeployRepository $deploy): JsonResponse
    {
        $request->validate(['revision' => ['nullable', 'string', 'regex:/\A[0-9a-fA-F]{40,64}\z/'], 'ref' => ['nullable', 'string', 'max:200']]);
        $build = $deploy->handle($user, $repository, $request->filled('revision') ? $request->string('revision')->toString() : null, 'manual', $request->filled('ref') ? $request->string('ref')->toString() : null);

        return response()->json(['redirect' => route('deploy.builds.show', [$project, $build->id], false)]);
    }
}
