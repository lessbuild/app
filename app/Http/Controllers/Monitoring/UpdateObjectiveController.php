<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\SaveServiceLevelObjective;
use App\Http\Requests\Monitoring\ServiceLevelObjectiveRequest;
use App\Models\Project;
use App\Models\ServiceLevelObjective;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

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
     * @return RedirectResponse
     */
    public function __invoke(ServiceLevelObjectiveRequest $request, #[CurrentUser] User $user, Project $project, ServiceLevelObjective $objective, SaveServiceLevelObjective $save): RedirectResponse
    {
        $target = $save->handle($project, $user, $request->validated(), $objective);

        return to_route('monitoring.objectives.show', [$project, $target->id])->with('status', __('Objective saved.'));
    }
}
