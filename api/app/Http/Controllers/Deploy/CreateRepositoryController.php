<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Data\Deploy\RepositoryFormOptions;
use App\Models\Project;
use App\Models\User;
use App\Queries\Deploy\RepositoryFormQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/projects/{project}/deploy/repositories/create`. */
final class CreateRepositoryController
{
    /**
     * Return the choices for connecting a repository (Git providers, websites and environments), with the project's
     * header for the page.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  RepositoryFormQuery  $form
     * @param  ProjectOverviewQuery  $overview
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, RepositoryFormQuery $form, ProjectOverviewQuery $overview): JsonResponse
    {
        return response()->json(['overview' => $overview->handle($project, $user), 'options' => RepositoryFormOptions::from($form->handle($project))]);
    }
}
