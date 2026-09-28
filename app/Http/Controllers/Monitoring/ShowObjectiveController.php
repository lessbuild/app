<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\Project;
use App\Models\ServiceLevelObjective;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Billing\Entitlements;
use App\Services\Monitoring\ServiceObjectiveBurnRate;
use App\Services\Monitoring\ServiceObjectiveReport;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowObjectiveController
{
    /**
     * An SLO's page: its report, and its burn rate and export when the plan includes them.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ServiceLevelObjective  $objective
     * @param  ProjectOverviewQuery  $overview
     * @param  ServiceObjectiveReport  $reports
     * @param  ServiceObjectiveBurnRate  $burnRates
     * @param  Entitlements  $entitlements
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ServiceLevelObjective $objective, ProjectOverviewQuery $overview, ServiceObjectiveReport $reports, ServiceObjectiveBurnRate $burnRates, Entitlements $entitlements): View
    {
        $plan = $entitlements->for($project->account);

        return view('monitoring.objective', [
            'overview' => $overview->handle($project, $user),
            'objective' => $objective,
            'report' => $reports->forObjective($objective),
            'burnRate' => $plan->has('monitoring.slo_burn_rate') ? $burnRates->forObjective($objective) : null,
            'canExport' => $plan->has('monitoring.slo_reports'),
            'canManage' => $user->can('update', $objective),
        ]);
    }
}
