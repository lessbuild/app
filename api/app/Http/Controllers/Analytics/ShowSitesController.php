<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Data\Analytics\SiteRow;
use App\Models\Project;
use App\Models\User;
use App\Queries\Analytics\ProjectSitesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use DateTimeZone;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowSitesController
{
    /**
     * List the project's Analytics sites, with the time zones a new site can report in.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ProjectSitesQuery  $sites
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectSitesQuery $sites): JsonResponse
    {
        return response()->json([
            'overview' => $overview->handle($project, $user),
            'sites' => array_map(SiteRow::from(...), $sites->handle($project)),
            'timezones' => DateTimeZone::listIdentifiers(),
            'canManage' => $user->can('manageService', [$project, 'analytics']),
        ]);
    }
}
