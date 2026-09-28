<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Models\Project;
use App\Models\User;
use App\Queries\Analytics\AnalyticsReportQuery;
use App\Queries\Analytics\ProjectSitesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** The Analytics report for one site: ?site, ?days and the path/source/campaign/device filters. */
final class ShowOverviewController
{
    /**
     * Show the report page. Unknown day ranges fall back to 30 days, and filters are cut to their column lengths.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ProjectSitesQuery  $sites
     * @param  AnalyticsReportQuery  $report
     * @return View
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectSitesQuery $sites, AnalyticsReportQuery $report): View
    {
        $site = $sites->selected($project, $request->query('site'));
        $days = in_array((int) $request->query('days'), [7, 30, 90, 365], true) ? (int) $request->query('days') : 30;
        $filters = [];
        foreach (['path' => 2048, 'source' => 255, 'campaign' => 150, 'device' => 32] as $key => $max) {
            $value = trim($request->string($key)->toString());
            $filters[$key] = $value !== '' ? mb_substr($value, 0, $max) : null;
        }

        return view('analytics.overview', [
            'overview' => $overview->handle($project, $user),
            'sites' => $sites->handle($project),
            'site' => $site,
            'days' => $days,
            'filters' => $filters,
            'summary' => $site !== null ? $report->handle($site, $days, $filters) : null,
            'canManage' => $user->can('manageService', [$project, 'analytics']),
        ]);
    }
}
