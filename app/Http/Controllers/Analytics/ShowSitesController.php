<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Models\Project;
use App\Models\User;
use App\Queries\Analytics\ProjectSitesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowSitesController
{
    /**
     * The project's analytics sites.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ProjectSitesQuery  $sites
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectSitesQuery $sites): View
    {
        return view('analytics.sites', [
            'overview' => $overview->handle($project, $user),
            'sites' => $sites->handle($project),
            'canManage' => $user->can('manageService', [$project, 'analytics']),
        ]);
    }
}
