<?php

namespace App\Modules\Deployer\Actions\LoadBalancer;

use App\Modules\Deployer\Exceptions\LoadBalancerOperationException;
use App\Modules\Deployer\Jobs\ApplyLoadBalancerJob;
use App\Modules\Deployer\Models\LoadBalancer;
use App\Modules\Deployer\Models\LoadBalancerNode;
use App\Modules\Deployer\Models\Server;

class AddLoadBalancerNodeAction
{
    /**
     * Add one node after rejecting self-routing, then queue the current Caddy configuration.
     *
     * @param  array<string, mixed>  $attributes  Validated upstream port and weight attributes.
     *
     * @throws LoadBalancerOperationException If the balancer server is selected as an application node.
     */
    public function handle(LoadBalancer $loadBalancer, Server $server, array $attributes): LoadBalancerNode
    {
        if ((int) $loadBalancer->server_id === (int) $server->id) {
            throw new LoadBalancerOperationException('The load balancer cannot route to itself.');
        }

        $node = $loadBalancer->nodes()->create([
            ...$attributes,
            'server_id' => $server->id,
            'is_enabled' => true,
        ]);
        ApplyLoadBalancerJob::dispatch($loadBalancer->id);

        return $node;
    }
}
