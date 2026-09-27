<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\ProjectAlertRulesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

final class EditObjectiveController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $objective, ProjectOverviewQuery $overview, ProjectAlertRulesQuery $rules): View
    {
        Gate::authorize('manageService', [$project, 'monitoring']);

        return view('monitoring.objective-form', ['overview' => $overview->handle($project, $user), 'objective' => $rules->objective($project, $objective)]);
    }
}
