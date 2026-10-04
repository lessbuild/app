<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Queries\Infrastructure\WebsitePageQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowWebsiteController
{
    /**
     * Show a website: setup and health, domains, database, backups, files and settings.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Website  $website
     * @param  ProjectOverviewQuery  $overview
     * @param  WebsitePageQuery  $page
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Website $website, ProjectOverviewQuery $overview, WebsitePageQuery $page): JsonResponse
    {
        return response()->json(['overview' => $overview->handle($project, $user), ...$page->handle($project, $website, $user)]);
    }
}
