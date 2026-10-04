<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\SaveServiceLevelObjective;
use App\Http\Requests\Monitoring\ServiceLevelObjectiveRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class StoreObjectiveController
{
    /**
     * Create an SLO.
     *
     * @param  ServiceLevelObjectiveRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  SaveServiceLevelObjective  $save
     * @return JsonResponse
     */
    public function __invoke(ServiceLevelObjectiveRequest $request, #[CurrentUser] User $user, Project $project, SaveServiceLevelObjective $save): JsonResponse
    {
        $objective = $save->handle($project, $user, $request->validated());

        return response()->json(['redirect' => route('monitoring.objectives.show', [$project, $objective->id], false), 'message' => __('Objective created.')]);
    }
}
