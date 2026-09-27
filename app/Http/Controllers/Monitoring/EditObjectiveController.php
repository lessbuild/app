<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\Project;
use App\Models\ServiceLevelObjective;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

final class EditObjectiveController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, ServiceLevelObjective $objective, ProjectOverviewQuery $overview): View
    {
        Gate::authorize('manageService', [$project, 'monitoring']);

        return view('monitoring.objective-form', ['overview' => $overview->handle($project, $user), 'objective' => $objective]);
    }
}
