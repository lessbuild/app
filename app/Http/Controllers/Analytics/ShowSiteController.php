<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Models\Project;
use App\Models\User;
use App\Queries\Analytics\ProjectSitesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use DateTimeZone;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

/** A site's setup: the tracking snippet, verification and settings. */
final class ShowSiteController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $site, ProjectOverviewQuery $overview, ProjectSitesQuery $sites): View
    {
        return view('analytics.site', [
            'overview' => $overview->handle($project, $user),
            'site' => $sites->find($project, $site),
            'canManage' => $user->can('manageService', [$project, 'analytics']),
            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
    }
}
