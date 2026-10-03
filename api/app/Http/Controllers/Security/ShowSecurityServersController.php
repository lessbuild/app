<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Models\Membership;
use App\Models\Project;
use App\Models\SecurityFinding;
use App\Models\ServerSshGrant;
use App\Models\User;
use App\Models\UserSshKey;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Security\ProjectServersQuery;
use App\Services\Billing\Entitlements;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowSecurityServersController
{
    /**
     * Show the project's servers with their open hardening findings, each one's update window, and who has SSH
     * access to it.
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
            'grants' => ServerSshGrant::query()->whereIn('server_id', $list->modelKeys())->with('user')->get()->groupBy('server_id'),
            'members' => Membership::query()->where('account_id', $project->account_id)->with('user')->get()->sortBy(fn (Membership $membership): string => $membership->user->name)->values(),
            'keyedUsers' => UserSshKey::query()->whereIn('user_id', Membership::query()->where('account_id', $project->account_id)->select('user_id'))->distinct()->pluck('user_id')->all(),
            'canManage' => $user->can('manageService', [$project, 'security']),
        ]);
    }
}
