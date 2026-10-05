<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Data\Monitoring\ObjectiveSummary;
use App\Models\Project;
use App\Models\ServiceLevelObjective;
use App\Models\User;
use App\Queries\Monitoring\ProjectAlertRulesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Billing\Entitlements;
use App\Services\Monitoring\ServiceObjectiveBurnRate;
use App\Services\Monitoring\ServiceObjectiveReport;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowObjectivesController
{
    /**
     * List the project's service level objectives and how much error budget each has left.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ProjectAlertRulesQuery  $rules
     * @param  ServiceObjectiveReport  $reports
     * @param  ServiceObjectiveBurnRate  $burnRates
     * @param  Entitlements  $entitlements
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectAlertRulesQuery $rules, ServiceObjectiveReport $reports, ServiceObjectiveBurnRate $burnRates, Entitlements $entitlements): JsonResponse
    {
        $burns = $entitlements->for($project->account)->has('monitoring.slo_burn_rate');

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'objectives' => array_map(function (ServiceLevelObjective $objective) use ($reports, $burnRates, $burns): array {
                $burn = $burns ? $burnRates->forObjective($objective) : null;

                return [
                    ...(array) ObjectiveSummary::from($objective->loadMissing('environment.project'), $reports->forObjective($objective)),
                    'burn' => $burn === null ? null : ['short' => $burn['short']['burn_rate'] ?? null, 'long' => $burn['long']['burn_rate'] ?? null],
                ];
            }, $rules->objectives($project)),
            'canManage' => $user->can('create', [ServiceLevelObjective::class, $project]),
        ]);
    }
}
