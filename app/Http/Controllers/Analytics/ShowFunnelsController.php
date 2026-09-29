<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Models\AnalyticsFunnel;
use App\Models\Project;
use App\Models\User;
use App\Queries\Analytics\FunnelReportQuery;
use App\Queries\Analytics\ProjectSitesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ShowFunnelsController
{
    /**
     * Show the site's funnels with where visitors dropped off over the last 30 days.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ProjectSitesQuery  $sites
     * @param  FunnelReportQuery  $reports
     * @return View
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectSitesQuery $sites, FunnelReportQuery $reports): View
    {
        $site = $sites->selected($project, $request->query('site'));
        $funnels = $site === null ? collect() : AnalyticsFunnel::query()->where('site_id', $site->id)->orderBy('name')->get();

        return view('analytics.funnels', [
            'overview' => $overview->handle($project, $user),
            'sites' => $sites->handle($project),
            'site' => $site,
            'funnels' => $funnels->map(fn (AnalyticsFunnel $funnel): array => ['funnel' => $funnel, 'report' => $reports->handle($funnel)])->all(),
            'canManage' => $site !== null && $user->can('update', $site),
        ]);
    }
}
