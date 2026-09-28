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
     * The account's websites and the plan's website limit.
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
        return view('infrastructure.websites', [
            'overview' => $overview->handle($project, $user),
            'websites' => $websites->handle($project->account_id),
            'limit' => $entitlements->for($project->account)->limit('deploy.websites.max'),
            'canManage' => $user->can('create', [Website::class, $project]),
        ]);
    }
}
