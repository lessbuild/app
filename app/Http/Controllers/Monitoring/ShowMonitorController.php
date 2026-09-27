<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\MonitorHistoryQuery;
use App\Queries\Monitoring\ProjectMonitorsQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowMonitorController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $monitor, ProjectOverviewQuery $overview, ProjectMonitorsQuery $monitors, MonitorHistoryQuery $history): View
    {
        $target = $monitors->find($project, $monitor, withArchived: true);

        return view('monitoring.monitor', [
            'overview' => $overview->handle($project, $user),
            'monitor' => $target,
            'history' => $history->handle($target),
            'canManage' => $user->can('manageService', [$project, 'monitoring']) && ! $target->trashed(),
            'issuedKey' => session('issued_key'),
        ]);
    }
}
