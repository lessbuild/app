<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\Project;
use App\Models\ServiceLevelObjective;
use App\Models\User;
use App\Queries\Monitoring\ProjectAlertRulesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Monitoring\ServiceObjectiveReport;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

/** Service level objectives: availability and latency targets, with their remaining error budget. */
final class ShowObjectivesController
{
    /**
     * The project's SLOs with each one's current report.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ProjectAlertRulesQuery  $rules
     * @param  ServiceObjectiveReport  $reports
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectAlertRulesQuery $rules, ServiceObjectiveReport $reports): View
    {
        $objectives = $rules->objectives($project);

        return view('monitoring.objectives', [
            'overview' => $overview->handle($project, $user),
            'objectives' => $objectives,
            'reports' => collect($objectives)->mapWithKeys(fn (ServiceLevelObjective $objective): array => [$objective->id => $reports->forObjective($objective)])->all(),
            'canManage' => $user->can('create', [ServiceLevelObjective::class, $project]),
        ]);
    }
}
