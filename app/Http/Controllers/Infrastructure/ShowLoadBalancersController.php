<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Models\LoadBalancer;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowLoadBalancersController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview): View
    {
        $servers = Server::query()->where('account_id', $project->account_id)->orderBy('name')->get();

        return view('infrastructure.load-balancers', [
            'overview' => $overview->handle($project, $user),
            'balancers' => LoadBalancer::query()->where('account_id', $project->account_id)->with(['server', 'website', 'nodes.server'])->orderBy('hostname')->get(),
            'servers' => $servers,
            'proxyServers' => $servers->filter(fn (Server $server): bool => $server->provisioning_status === Server::STATUS_ACTIVE && in_array('caddy', $server->type->installs(), true)),
            'websites' => Website::query()->where('account_id', $project->account_id)->orderBy('name')->get(),
            'canCreate' => $user->can('create', [LoadBalancer::class, $project]),
            'canManage' => $user->can('manageAny', [LoadBalancer::class, $project]),
        ]);
    }
}
