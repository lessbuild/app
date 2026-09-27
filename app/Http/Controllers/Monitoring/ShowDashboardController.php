<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\DashboardReportQuery;
use App\Queries\Monitoring\DashboardsQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Telemetry\TelemetrySummaryQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowDashboardController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $dashboard, ProjectOverviewQuery $overview, DashboardsQuery $dashboards, DashboardReportQuery $report): View
    {
        $record = $dashboards->find($project->account_id, $dashboard);

        return view('monitoring.dashboard', [
            'overview' => $overview->handle($project, $user),
            'dashboard' => $record,
            'rangeLabel' => __(TelemetrySummaryQuery::RANGES[$record->range] ?? 'Last 24 hours'),
            'widgets' => $report->handle($record),
            'canManage' => $user->can('update', $project->account),
        ]);
    }
}
