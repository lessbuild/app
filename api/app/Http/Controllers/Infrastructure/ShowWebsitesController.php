<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Queries\Infrastructure\WebsitesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Billing\Entitlements;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

/** The account's websites (shared across projects, like servers). */
final class ShowWebsitesController
{
    /**
     * Show the account's websites and the plan's website limit, with the servers and environments the create and
     * import modals offer to people who can add websites.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  WebsitesQuery  $websites
     * @param  Entitlements  $entitlements
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, WebsitesQuery $websites, Entitlements $entitlements): View
    {
        $canManage = $user->can('create', [Website::class, $project]);

        return view('infrastructure.websites', [
            'overview' => $overview->handle($project, $user),
            'websites' => $websites->handle($project->account_id),
            'limit' => $entitlements->for($project->account)->limit('deploy.websites.max'),
            'canManage' => $canManage,
            'hosts' => $canManage ? $websites->hosts($project->account_id) : collect(),
            'environments' => $canManage ? $websites->environments($project->account) : collect(),
        ]);
    }
}
