<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\SaveServiceLevelObjective;
use App\Http\Requests\Monitoring\ServiceLevelObjectiveRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class StoreObjectiveController
{
    public function __invoke(ServiceLevelObjectiveRequest $request, #[CurrentUser] User $user, Project $project, SaveServiceLevelObjective $save): RedirectResponse
    {
        $objective = $save->handle($project, $user, $request->validated());

        return to_route('monitoring.objectives.show', [$project, $objective->id])->with('status', __('Objective created.'));
    }
}
