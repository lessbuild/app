<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\AlertDestination;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

final class EditMonitorController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, Monitor $monitor, ProjectOverviewQuery $overview): View
    {
        Gate::authorize('manageService', [$project, 'monitoring']);

        return view('monitoring.monitor-form', [
            'overview' => $overview->handle($project, $user),
            'monitor' => $monitor,
            'checkType' => $monitor->type,
            'destinations' => AlertDestination::query()->where('account_id', $project->account_id)->orderBy('name')->get(),
            'selectedDestinations' => $monitor->destinations()->pluck('alert_destinations.id')->all(),
            'routing' => $monitor->destinations()->first()?->getRelation('pivot'),
        ]);
    }
}
