<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Models\LoadBalancer;
use App\Models\LoadBalancerNode;
use App\Models\Server;
use App\Models\User;
use App\Services\Infrastructure\LoadBalancerChanges;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SaveLoadBalancerNode
{
    /**
     * Adds or changes a server behind a load balancer.
     *
     * @param  LoadBalancerChanges  $changes  Re-applies the load balancer's configuration.
     */
    public function __construct(private readonly LoadBalancerChanges $changes) {}

    /**
     * Send traffic to a server in the account (new node), or change a node's port, weight or whether it's in rotation.
     *
     * @param  User  $actor
     * @param  LoadBalancer  $balancer
     * @param  array{server_id?: int|string, upstream_port: int|string, weight: int|string, is_enabled?: bool|string|null}  $data
     * @param  LoadBalancerNode|null  $node
     * @return LoadBalancerNode
     */
    public function handle(User $actor, LoadBalancer $balancer, array $data, ?LoadBalancerNode $node = null): LoadBalancerNode
    {
        Gate::forUser($actor)->authorize('update', $balancer);
        if ($node === null) {
            $server = Server::query()->where('account_id', $balancer->account_id)->find((int) ($data['server_id'] ?? 0));
            if ($server === null) {
                throw ValidationException::withMessages(['server_id' => __('Choose a server in this account.')]);
            }
            if ($server->id === $balancer->server_id) {
                throw ValidationException::withMessages(['server_id' => __('The load balancer can’t send traffic to itself.')]);
            }
            if ($balancer->nodes()->where('server_id', $server->id)->exists()) {
                throw ValidationException::withMessages(['server_id' => __('That server is already a node.')]);
            }
            $node = new LoadBalancerNode;
            $node->forceFill(['load_balancer_id' => $balancer->id, 'server_id' => $server->id]);
        }
        $this->changes->apply($balancer, fn () => $node->forceFill([
            'upstream_port' => (int) $data['upstream_port'], 'weight' => (int) $data['weight'], 'is_enabled' => (bool) ($data['is_enabled'] ?? true),
        ])->save());

        return $node;
    }
}
