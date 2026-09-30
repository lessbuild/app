<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Contracts\Analytics\GoogleAnalyticsData;
use App\Contracts\Analytics\SearchConsole;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\StorageBucket;
use App\Models\User;
use App\Queries\Analytics\SearchConsolePropertiesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use DateTimeZone;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use RuntimeException;

/** A site's setup: the tracking snippet, verification and settings. */
final class ShowSiteController
{
    /**
     * Show a site's setup page, with the Google Analytics properties to import from when an account is connected.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  ProjectOverviewQuery  $overview
     * @param  SearchConsolePropertiesQuery  $searchConsoleProperties
     * @param  SearchConsole  $searchConsole
     * @param  GoogleAnalyticsData  $googleAnalytics
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, AnalyticsSite $site, ProjectOverviewQuery $overview, SearchConsolePropertiesQuery $searchConsoleProperties, SearchConsole $searchConsole, GoogleAnalyticsData $googleAnalytics): View
    {
        $imports = $site->imports()->latest('id')->get();
        $connected = $imports->firstWhere('status', 'connected');
        $gaProperties = [];
        $gaError = null;
        if ($connected !== null && $connected->refresh_token !== null) {
            try {
                $gaProperties = $googleAnalytics->properties($connected->refresh_token);
            } catch (RuntimeException $exception) {
                $gaError = $exception->getMessage();
            }
        }

        return view('analytics.site', [
            'buckets' => StorageBucket::query()->where('project_id', $project->id)->orderBy('name')->get(),
            'googleAnalytics' => ['configured' => $googleAnalytics->configured(), 'imports' => $imports, 'connected' => $connected, 'properties' => $gaProperties, 'error' => $gaError],
            'searchConsole' => ['configured' => $searchConsole->configured(), ...$searchConsoleProperties->handle($site)],
            'overview' => $overview->handle($project, $user),
            'site' => $site,
            'canManage' => $user->can('manageService', [$project, 'analytics']),
            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
    }
}
