<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\Project;
use App\Models\ServiceLevelObjective;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class EditObjectiveController
{
    /**
     * Describe the form for editing an objective, with its current settings.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ServiceLevelObjective  $objective
     * @param  ProjectOverviewQuery  $overview
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ServiceLevelObjective $objective, ProjectOverviewQuery $overview): JsonResponse
    {
        return response()->json([
            'overview' => $overview->handle($project, $user),
            'objective' => [
                'id' => $objective->id, 'name' => $objective->name, 'environmentId' => $objective->environment_id, 'indicator' => $objective->indicator,
                'target' => (float) $objective->target, 'windowDays' => $objective->window_days, 'latencyThresholdMs' => $objective->latency_threshold_ms,
                'statusMin' => $objective->status_min, 'statusMax' => $objective->status_max, 'service' => $objective->service, 'route' => $objective->route,
                'enabled' => (bool) $objective->enabled,
            ],
        ]);
    }
}
