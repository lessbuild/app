<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Data\Monitoring\AlertRuleSummary;
use App\Models\AlertRule;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\ProjectAlertRulesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowAlertRulesController
{
    /**
     * List the project's alert rules and how each is doing.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ProjectAlertRulesQuery  $rules
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectAlertRulesQuery $rules): JsonResponse
    {
        return response()->json([
            'overview' => $overview->handle($project, $user),
            'rules' => array_map(fn (AlertRule $rule): AlertRuleSummary => AlertRuleSummary::from($rule), $rules->handle($project)),
            'canManage' => $user->can('create', [AlertRule::class, $project]),
        ]);
    }
}
