<?php

namespace App\Actions\LoadBalancer;

use App\Jobs\ApplyLoadBalancerJob;
use App\Models\LoadBalancerNode;

class DeleteLoadBalancerNodeAction
{
    /**
     * Remove a node and queue the balancer's updated Caddy configuration.
     */
    public function handle(LoadBalancerNode $node): void
    {
        $loadBalancerId = $node->load_balancer_id;
        $node->delete();
        ApplyLoadBalancerJob::dispatch($loadBalancerId);
    }
}
