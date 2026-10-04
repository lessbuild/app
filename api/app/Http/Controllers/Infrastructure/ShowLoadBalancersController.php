<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Models\LoadBalancer;
use App\Models\LoadBalancerNode;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowLoadBalancersController
{
    /**
     * List the account's load balancers and their nodes, with the servers and websites the forms can choose.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview): JsonResponse
    {
        $servers = Server::query()->where('account_id', $project->account_id)->orderBy('name')->get();

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'balancers' => LoadBalancer::query()->where('account_id', $project->account_id)->with(['server', 'website', 'nodes.server'])->orderBy('hostname')->get()
                ->map(fn (LoadBalancer $balancer): array => [
                    'id' => $balancer->id,
                    'hostname' => $balancer->hostname,
                    'healthPath' => $balancer->health_path,
                    'serverId' => $balancer->server_id,
                    'server' => $balancer->server->label(),
                    'serverIp' => $balancer->server->public_ip,
                    'websiteId' => $balancer->website_id,
                    'website' => $balancer->website?->name,
                    'status' => $balancer->status,
                    'removing' => $balancer->isRemoving(),
                    'error' => $balancer->last_error,
                    'nodes' => $balancer->nodes->sortBy('id')->map(fn (LoadBalancerNode $node): array => [
                        'id' => $node->id, 'serverId' => $node->server_id, 'server' => $node->server->label(), 'ip' => $node->server->public_ip, 'port' => $node->upstream_port,
                        'weight' => $node->weight, 'enabled' => (bool) $node->is_enabled, 'serverActive' => $node->server->provisioning_status === Server::STATUS_ACTIVE,
                    ])->values(),
                ])->values(),
            'servers' => $servers->map(fn (Server $server): array => ['value' => (string) $server->id, 'label' => $server->label()])->values(),
            'proxyServers' => $servers->filter(fn (Server $server): bool => $server->provisioning_status === Server::STATUS_ACTIVE && in_array('caddy', $server->type->installs(), true))
                ->map(fn (Server $server): array => ['value' => (string) $server->id, 'label' => $server->label().' ('.$server->type->label().')'])->values(),
            'websites' => Website::query()->where('account_id', $project->account_id)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Website $website): array => ['value' => (string) $website->id, 'label' => $website->name])->values(),
            'canCreate' => $user->can('create', [LoadBalancer::class, $project]),
            'canManage' => $user->can('manageAny', [LoadBalancer::class, $project]),
        ]);
    }
}
