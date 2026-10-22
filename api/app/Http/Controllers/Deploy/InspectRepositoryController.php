<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Jobs\Deploy\InspectRepository;
use App\Models\Project;
use App\Models\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class InspectRepositoryController
{
    /**
     * Read the repository again now (Look again on the repository page).
     *
     * @param  Project  $project
     * @param  Repository  $repository
     * @return JsonResponse
     */
    public function __invoke(Project $project, Repository $repository): JsonResponse
    {
        InspectRepository::dispatchSync($repository->id);
        $repository->refresh();
        if ($repository->stack_error !== null) {
            throw ValidationException::withMessages(['repository' => $repository->stack_error]);
        }

        return response()->json(['redirect' => route('deploy.repositories.show', [$project, $repository], false), 'message' => __('Read the repository again.')]);
    }
}
