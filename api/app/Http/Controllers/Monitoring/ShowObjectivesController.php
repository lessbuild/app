<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Data\Monitoring\ObjectiveSummary;
use App\Models\Project;
use App\Models\ServiceLevelObjective;
use App\Models\User;
use App\Queries\Monitoring\ProjectAlertRulesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
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
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectAlertRulesQuery $rules, ServiceObjectiveReport $reports): JsonResponse
    {
        return response()->json([
            'overview' => $overview->handle($project, $user),
            'objectives' => array_map(fn (ServiceLevelObjective $objective): ObjectiveSummary => ObjectiveSummary::from($objective->loadMissing('environment.project'), $reports->forObjective($objective)), $rules->objectives($project)),
            'canManage' => $user->can('create', [ServiceLevelObjective::class, $project]),
        ]);
    }
}
