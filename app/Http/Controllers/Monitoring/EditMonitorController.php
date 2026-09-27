<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\AlertDestination;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\ProjectMonitorsQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

final class EditMonitorController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $monitor, ProjectOverviewQuery $overview, ProjectMonitorsQuery $monitors): View
    {
        Gate::authorize('manageService', [$project, 'monitoring']);
        $target = $monitors->find($project, $monitor);

        return view('monitoring.monitor-form', [
            'overview' => $overview->handle($project, $user),
            'monitor' => $target,
            'checkType' => $target->type,
            'destinations' => AlertDestination::query()->where('account_id', $project->account_id)->orderBy('name')->get(),
            'selectedDestinations' => $target->destinations()->pluck('alert_destinations.id')->all(),
            'routing' => $target->destinations()->first()?->getRelation('pivot'),
        ]);
    }
}
