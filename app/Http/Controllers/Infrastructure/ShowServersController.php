<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use App\Queries\Infrastructure\ServersQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Billing\Entitlements;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

/** The account's servers. They're shared, so every project shows the same list. */
final class ShowServersController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ServersQuery $servers, Entitlements $entitlements): View
    {
        return view('infrastructure.servers', [
            'overview' => $overview->handle($project, $user),
            'servers' => $servers->handle($project->account_id),
            'limit' => $entitlements->for($project->account)->limit('infrastructure.servers.max'),
            'canManage' => $user->can('create', [Server::class, $project]),
        ]);
    }
}
