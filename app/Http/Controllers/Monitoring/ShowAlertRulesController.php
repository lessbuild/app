<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\AlertRule;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\ProjectAlertRulesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

/** Alert rules on the project's telemetry: error rates, latency, exceptions, log patterns, metrics and SLO burn. */
final class ShowAlertRulesController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectAlertRulesQuery $rules): View
    {
        return view('monitoring.rules', [
            'overview' => $overview->handle($project, $user),
            'rules' => $rules->handle($project),
            'canManage' => $user->can('create', [AlertRule::class, $project]),
        ]);
    }
}
