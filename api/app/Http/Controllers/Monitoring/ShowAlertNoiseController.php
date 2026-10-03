<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\AlertNoiseQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowAlertNoiseController
{
    /**
     * Show which alert rules and monitors were noisiest over the last 30 days, with suggestions for tuning them.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  AlertNoiseQuery  $noise
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, AlertNoiseQuery $noise): View
    {
        return view('monitoring.noise', [
            'overview' => $overview->handle($project, $user),
            'rows' => $noise->handle($project),
        ]);
    }
}
