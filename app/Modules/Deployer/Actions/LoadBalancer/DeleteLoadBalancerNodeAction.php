<?php

namespace App\Modules\Deployer\Actions\LoadBalancer;

use App\Modules\Deployer\Models\LoadBalancer;
use App\Modules\Deployer\Models\LoadBalancerNode;

class DeleteLoadBalancerNodeAction
{
    public function __construct(private readonly QueueLoadBalancerApplyAction $queueApply) {}

    /**
     * Remove a node and queue the balancer's updated Caddy configuration.
     */
    public function handle(LoadBalancerNode $node): void
    {
        $loadBalancerId = $node->load_balancer_id;
        $loadBalancer = LoadBalancer::query()->find($loadBalancerId);

        if ($loadBalancer === null) {
            $node->delete();

            return;
        }

        $this->queueApply->handle($loadBalancer, function (LoadBalancer $current) use ($node): void {
            $current->nodes()->whereKey($node->getKey())->delete();
        });
    }
}
