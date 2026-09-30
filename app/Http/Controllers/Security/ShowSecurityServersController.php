<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Models\Project;
use App\Models\SecurityFinding;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Security\ProjectServersQuery;
use App\Services\Billing\Entitlements;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowSecurityServersController
{
    /**
     * Show the project's servers with their open hardening findings, and each one's update window.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ProjectServersQuery  $servers
     * @param  Entitlements  $entitlements
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectServersQuery $servers, Entitlements $entitlements): View
    {
        $list = $servers->handle($project);
        $findings = SecurityFinding::query()->where('project_id', $project->id)->where('source', 'servers')->where('status', 'open')
            ->whereIn('scope', $list->map(fn ($server): string => "server:{$server->id}"))->get()->groupBy('scope');

        return view('security.servers', [
            'overview' => $overview->handle($project, $user),
            'servers' => $list,
            'findings' => $findings,
            'included' => $entitlements->for($project->account)->has('security.servers'),
            'canManage' => $user->can('manageService', [$project, 'security']),
        ]);
    }
}
