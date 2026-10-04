<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\SaveRepository;
use App\Http\Requests\Deploy\RepositoryRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class StoreRepositoryController
{
    /**
     * Connect a repository to the project.
     *
     * @param  RepositoryRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  SaveRepository  $save
     * @return JsonResponse
     */
    public function __invoke(RepositoryRequest $request, #[CurrentUser] User $user, Project $project, SaveRepository $save): JsonResponse
    {
        $repository = $save->handle($user, $project, $request->repository());

        return response()->json(['redirect' => route('deploy.repositories.show', [$project, $repository->id], false), 'message' => __('Repository connected. Deploy it when you’re ready.')]);
    }
}
