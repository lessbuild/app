<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\SaveRepository;
use App\Http\Requests\Deploy\RepositoryRequest;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class UpdateRepositoryController
{
    /**
     * Save a repository's settings; the next deploy uses them.
     *
     * @param  RepositoryRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Repository  $repository
     * @param  SaveRepository  $save
     * @return JsonResponse
     */
    public function __invoke(RepositoryRequest $request, #[CurrentUser] User $user, Project $project, Repository $repository, SaveRepository $save): JsonResponse
    {
        $save->handle($user, $project, $request->repository(), $repository);

        return response()->json(['redirect' => route('deploy.repositories.show', [$project, $repository->id, 'tab' => 'settings'], false), 'message' => __('Repository saved. The next deploy uses these settings.')]);
    }
}
