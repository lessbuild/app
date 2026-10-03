<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Models\ApiToken;
use App\Models\Membership;
use App\Models\Project;
use App\Models\SecurityAccessReview;
use App\Models\ServerSshGrant;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Security\ProjectServersQuery;
use App\Services\Billing\Entitlements;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowSecurityAccessController
{
    /**
     * Show everyone and everything with access (members, API tokens, SSH access to the project's servers) as an
     * access review, with the past reviews.
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
        return view('security.access', [
            'overview' => $overview->handle($project, $user),
            'members' => Membership::query()->where('account_id', $project->account_id)->with('user')->get()->sortBy(fn (Membership $membership): string => $membership->user->name)->values(),
            'tokens' => ApiToken::query()->where('account_id', $project->account_id)->orderBy('name')->get(),
            'tokenOwners' => User::query()->whereIn('id', ApiToken::query()->where('account_id', $project->account_id)->select('tokenable_id'))->pluck('name', 'id'),
            'grants' => ServerSshGrant::query()->whereIn('server_id', $servers->handle($project)->modelKeys())->with(['user', 'server'])->get(),
            'reviews' => SecurityAccessReview::query()->where('project_id', $project->id)->with('reviewer')->latest('id')->limit(10)->get(),
            'requireTwoFactor' => $project->account->require_two_factor,
            'included' => $entitlements->for($project->account)->has('security.access_reviews'),
            'canManage' => $user->can('manageService', [$project, 'security']),
        ]);
    }
}
