<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\Monitor;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\MonitorHistoryQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowMonitorController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, Monitor $monitor, ProjectOverviewQuery $overview, MonitorHistoryQuery $history): View
    {

        return view('monitoring.monitor', [
            'overview' => $overview->handle($project, $user),
            'monitor' => $monitor,
            'history' => $history->handle($monitor),
            'canManage' => $user->can('manageService', [$project, 'monitoring']) && ! $monitor->trashed(),
            'issuedKey' => session('issued_key'),
        ]);
    }
}
