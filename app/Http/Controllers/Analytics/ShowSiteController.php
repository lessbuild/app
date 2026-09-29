<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Contracts\Analytics\SearchConsole;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use App\Queries\Analytics\SearchConsolePropertiesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use DateTimeZone;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

/** A site's setup: the tracking snippet, verification and settings. */
final class ShowSiteController
{
    /**
     * Show a site's setup page.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  ProjectOverviewQuery  $overview
     * @param  SearchConsolePropertiesQuery  $searchConsoleProperties
     * @param  SearchConsole  $searchConsole
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, AnalyticsSite $site, ProjectOverviewQuery $overview, SearchConsolePropertiesQuery $searchConsoleProperties, SearchConsole $searchConsole): View
    {
        return view('analytics.site', [
            'searchConsole' => ['configured' => $searchConsole->configured(), ...$searchConsoleProperties->handle($site)],
            'overview' => $overview->handle($project, $user),
            'site' => $site,
            'canManage' => $user->can('manageService', [$project, 'analytics']),
            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
    }
}
