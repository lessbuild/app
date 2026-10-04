<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Data\Infrastructure\WebsiteFormOptions;
use App\Models\Project;
use App\Models\User;
use App\Queries\Infrastructure\WebsitesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class CreateWebsiteController
{
    /**
     * Describe the forms for creating or importing a website: the servers it can go on and the environments it can
     * serve.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  WebsitesQuery  $websites
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, WebsitesQuery $websites): JsonResponse
    {
        return response()->json([
            'overview' => $overview->handle($project, $user),
            'options' => WebsiteFormOptions::for($websites, $project->account),
        ]);
    }
}
