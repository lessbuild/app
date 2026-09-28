<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\Dashboard;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\DashboardReportQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Telemetry\TelemetrySummaryQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowDashboardController
{
    /**
     * Show a dashboard with its widgets filled in.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Dashboard  $dashboard
     * @param  ProjectOverviewQuery  $overview
     * @param  DashboardReportQuery  $report
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Dashboard $dashboard, ProjectOverviewQuery $overview, DashboardReportQuery $report): View
    {

        return view('monitoring.dashboard', [
            'overview' => $overview->handle($project, $user),
            'dashboard' => $dashboard,
            'rangeLabel' => __(TelemetrySummaryQuery::RANGES[$dashboard->range] ?? 'Last 24 hours'),
            'widgets' => $report->handle($dashboard),
            'canManage' => $user->can('update', $dashboard),
        ]);
    }
}
