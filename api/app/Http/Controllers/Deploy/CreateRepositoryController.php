<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Data\Deploy\RepositoryFormOptions;
use App\Models\Project;
use App\Queries\Deploy\RepositoryFormQuery;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/projects/{project}/deploy/repositories/create`. */
final class CreateRepositoryController
{
    /**
     * Return the choices for connecting a repository: Git providers, websites and environments.
     *
     * @param  Project  $project
     * @param  RepositoryFormQuery  $form
     * @return JsonResponse
     */
    public function __invoke(Project $project, RepositoryFormQuery $form): JsonResponse
    {
        return response()->json(['options' => RepositoryFormOptions::from($form->handle($project))]);
    }
}
