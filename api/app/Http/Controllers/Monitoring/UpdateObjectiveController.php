<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\SaveServiceLevelObjective;
use App\Http\Requests\Monitoring\ServiceLevelObjectiveRequest;
use App\Models\Project;
use App\Models\ServiceLevelObjective;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class UpdateObjectiveController
{
    /**
     * Save an SLO.
     *
     * @param  ServiceLevelObjectiveRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  ServiceLevelObjective  $objective
     * @param  SaveServiceLevelObjective  $save
     * @return JsonResponse
     */
    public function __invoke(ServiceLevelObjectiveRequest $request, #[CurrentUser] User $user, Project $project, ServiceLevelObjective $objective, SaveServiceLevelObjective $save): JsonResponse
    {
        $target = $save->handle($project, $user, $request->validated(), $objective);

        return response()->json(['redirect' => route('monitoring.objectives.show', [$project, $target->id], false), 'message' => __('Objective saved.')]);
    }
}
