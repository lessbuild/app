<?php

namespace App\Modules\Deployer\Actions\LoadBalancer;

use App\Modules\Deployer\Jobs\ApplyLoadBalancerJob;
use App\Modules\Deployer\Models\LoadBalancerNode;

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
