<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Security\SecurityOverviewQuery;
use App\Services\Billing\Entitlements;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowSecurityOverviewController
{
    /**
     * Show the project's Security overview: its score, the checks and when they last ran, the deploy gate for each
     * environment, and the most serious open findings.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  SecurityOverviewQuery  $security
     * @param  Entitlements  $entitlements
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, SecurityOverviewQuery $security, Entitlements $entitlements): View
    {
        return view('security.overview', [
            'overview' => $overview->handle($project, $user),
            'security' => $security->handle($project),
            'canManage' => $user->can('manageService', [$project, 'security']),
            'environments' => $project->hasService('deploy') ? $project->environments()->orderBy('name')->get() : collect(),
            'gateIncluded' => $entitlements->for($project->account)->has('security.deploy_gate'),
        ]);
    }
}
