<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectDomainsQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/projects/{project}/domains`. */
final class ShowDomainsController
{
    /**
     * Return the project's domains, unverified first, with the TXT record that proves each one.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ProjectDomainsQuery  $domains
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectDomainsQuery $domains): JsonResponse
    {
        return response()->json(['overview' => $overview->handle($project, $user), 'domains' => $domains->handle($project)]);
    }
}
