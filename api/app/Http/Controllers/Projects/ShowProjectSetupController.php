<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Projects\ProjectSetupQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/projects/{project}/setup`. */
final class ShowProjectSetupController
{
    /**
     * Return the setup guide: each step from provider to analytics, ticking itself off, with where to do the next one.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ProjectSetupQuery  $setup
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectSetupQuery $setup): JsonResponse
    {
        return response()->json([
            'overview' => $overview->handle($project, $user),
            'setup' => $setup->handle($project),
            'canChange' => $user->can('update', $project),
        ]);
    }
}
