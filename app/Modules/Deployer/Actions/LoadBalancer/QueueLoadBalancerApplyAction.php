<?php

namespace App\Modules\Deployer\Actions\LoadBalancer;

use App\Modules\Deployer\Jobs\ApplyLoadBalancerJob;
use App\Modules\Deployer\Models\LoadBalancer;

class QueueLoadBalancerApplyAction
{
    /**
     * Queue Caddy configuration generation for an already-authorized balancer.
     */
    public function handle(LoadBalancer $loadBalancer): void
    {
        ApplyLoadBalancerJob::dispatch($loadBalancer->id);
    }
}
