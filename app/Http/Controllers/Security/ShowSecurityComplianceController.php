<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Models\Project;
use App\Models\SecurityAccessReview;
use App\Models\SecurityFinding;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Billing\Entitlements;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowSecurityComplianceController
{
    /**
     * Show the compliance page: what the evidence pack contains, how the project stands, and the download.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  Entitlements  $entitlements
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, Entitlements $entitlements): View
    {
        return view('security.compliance', [
            'overview' => $overview->handle($project, $user),
            'lastReview' => SecurityAccessReview::query()->where('project_id', $project->id)->latest('id')->first(),
            'openSerious' => SecurityFinding::query()->where('project_id', $project->id)->where('status', 'open')->whereIn('severity', ['critical', 'high'])->count(),
            'resolved' => SecurityFinding::query()->where('project_id', $project->id)->where('status', 'resolved')->where('resolved_at', '>=', now()->subYear())->count(),
            'included' => $entitlements->for($project->account)->has('security.compliance'),
            'canManage' => $user->can('manageService', [$project, 'security']),
        ]);
    }
}
