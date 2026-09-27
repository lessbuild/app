<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\ProjectAlertRulesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Billing\Entitlements;
use App\Services\Monitoring\ServiceObjectiveBurnRate;
use App\Services\Monitoring\ServiceObjectiveReport;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowObjectiveController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $objective, ProjectOverviewQuery $overview, ProjectAlertRulesQuery $rules, ServiceObjectiveReport $reports, ServiceObjectiveBurnRate $burnRates, Entitlements $entitlements): View
    {
        $target = $rules->objective($project, $objective);
        $plan = $entitlements->for($project->account);

        return view('monitoring.objective', [
            'overview' => $overview->handle($project, $user),
            'objective' => $target,
            'report' => $reports->forObjective($target),
            'burnRate' => $plan->has('monitoring.slo_burn_rate') ? $burnRates->forObjective($target) : null,
            'canExport' => $plan->has('monitoring.slo_reports'),
            'canManage' => $user->can('manageService', [$project, 'monitoring']),
        ]);
    }
}
