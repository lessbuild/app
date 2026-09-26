<?php

namespace App\Modules\Deployer\Actions\LoadBalancer;

use App\Modules\Deployer\Exceptions\LoadBalancerOperationException;
use App\Modules\Deployer\Models\LoadBalancer;
use App\Modules\Deployer\Models\LoadBalancerNode;
use App\Modules\Deployer\Models\Server;
use LogicException;

class AddLoadBalancerNodeAction
{
    public function __construct(private readonly QueueLoadBalancerApplyAction $queueApply) {}

    /**
     * Add one node after rejecting self-routing, then queue the current Caddy configuration.
     *
     * @param  array<string, mixed>  $attributes  Validated upstream port and weight attributes.
     *
     * @throws LoadBalancerOperationException If the balancer server is selected as an application node.
     */
    public function handle(LoadBalancer $loadBalancer, Server $server, array $attributes): LoadBalancerNode
    {
        $node = null;
        $this->queueApply->handle($loadBalancer, function (LoadBalancer $current) use ($server, $attributes, &$node): void {
            if ((int) $current->server_id === (int) $server->id) {
                throw new LoadBalancerOperationException('The load balancer cannot route to itself.');
            }

            $node = $current->nodes()->create([
                ...$attributes,
                'server_id' => $server->id,
                'is_enabled' => true,
            ]);
        });

        return $node ?? throw new LogicException('A load-balancer node must be created before its configuration is queued.');
    }
}
