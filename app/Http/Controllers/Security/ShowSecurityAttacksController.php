<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Models\Project;
use App\Models\SecurityBlock;
use App\Models\SecuritySetting;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Billing\Entitlements;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowSecurityAttacksController
{
    /**
     * Show the addresses blocked for attacking the project's servers (active ones first, then the last 50 lifted), and
     * the blocking settings.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  Entitlements  $entitlements
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, Entitlements $entitlements): View
    {
        $blocks = SecurityBlock::query()->where('project_id', $project->id)->with('server')->latest('id');

        return view('security.attacks', [
            'overview' => $overview->handle($project, $user),
            'active' => (clone $blocks)->whereNull('lifted_at')->where('expires_at', '>', now())->get(),
            'history' => (clone $blocks)->where(fn ($query) => $query->whereNotNull('lifted_at')->orWhere('expires_at', '<=', now()))->limit(50)->get(),
            'settings' => SecuritySetting::forProject($project->id),
            'included' => $entitlements->for($project->account)->has('security.autoblock'),
            'canManage' => $user->can('manageService', [$project, 'security']),
        ]);
    }
}
