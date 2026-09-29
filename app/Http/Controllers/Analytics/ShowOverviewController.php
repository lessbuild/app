<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Models\Project;
use App\Models\User;
use App\Queries\Analytics\AnalyticsReportQuery;
use App\Queries\Analytics\LiveVisitorsQuery;
use App\Queries\Analytics\ProjectSitesQuery;
use App\Queries\Analytics\SiteReleasesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** The Analytics report for one site: ?site, ?days and the path/source/campaign/device filters. */
final class ShowOverviewController
{
    /**
     * Show the report page, with the releases that went live in the period. Unknown day ranges fall back to 30 days,
     * and filters are cut to their column lengths.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ProjectSitesQuery  $sites
     * @param  AnalyticsReportQuery  $report
     * @param  SiteReleasesQuery  $releases
     * @param  LiveVisitorsQuery  $live
     * @return View
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectSitesQuery $sites, AnalyticsReportQuery $report, SiteReleasesQuery $releases, LiveVisitorsQuery $live): View
    {
        $site = $sites->selected($project, $request->query('site'));
        $days = in_array((int) $request->query('days'), [1, 7, 30, 90, 365], true) ? (int) $request->query('days') : 30;
        $filters = [];
        foreach (['path' => 2048, 'source' => 255, 'campaign' => 150, 'device' => 32] as $key => $max) {
            $value = trim($request->string($key)->toString());
            $filters[$key] = $value !== '' ? mb_substr($value, 0, $max) : null;
        }

        // The "right now" panel refreshes itself; answer it with just that panel instead of the whole report.
        if ($site !== null && $request->hasHeader('X-Live-Region')) {
            return view('analytics._live', ['recent' => $live->handle($site, $filters)]);
        }

        $summary = $site !== null ? $report->handle($site, $days, $filters) : null;

        return view('analytics.overview', [
            'overview' => $overview->handle($project, $user),
            'sites' => $sites->handle($project),
            'site' => $site,
            'days' => $days,
            'filters' => $filters,
            'summary' => $summary,
            'releases' => $site !== null && $summary !== null ? $releases->handle($site, $summary['range']['start'], $summary['range']['end']) : collect(),
            'canManage' => $user->can('manageService', [$project, 'analytics']),
        ]);
    }
}
