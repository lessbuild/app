<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Queries\Security\ProjectZonesQuery;
use App\Services\Billing\Entitlements;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowSecurityFirewallController
{
    /**
     * Show the Cloudflare zones behind the project's domains, with their security settings.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ProjectZonesQuery  $zones
     * @param  Entitlements  $entitlements
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ProjectZonesQuery $zones, Entitlements $entitlements): View
    {
        return view('security.firewall', [
            'overview' => $overview->handle($project, $user),
            'zones' => $zones->handle($project),
            'included' => $entitlements->for($project->account)->has('security.waf'),
            'canManage' => $user->can('manageService', [$project, 'security']),
        ]);
    }
}
