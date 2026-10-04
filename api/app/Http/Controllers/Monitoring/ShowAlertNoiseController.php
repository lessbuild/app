<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\AlertNoiseQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowAlertNoiseController
{
    /**
     * Rank the alerts that fired most in the last 30 days, with how often they flapped and went unacknowledged.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  AlertNoiseQuery  $noise
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, AlertNoiseQuery $noise): JsonResponse
    {
        return response()->json([
            'overview' => $overview->handle($project, $user),
            'rows' => $noise->handle($project),
            'flapMinutes' => AlertNoiseQuery::FLAP_MINUTES,
        ]);
    }
}
