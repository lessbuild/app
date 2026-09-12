<?php

namespace App\Actions\LoadBalancer;

use App\Jobs\ApplyLoadBalancerJob;
use App\Models\LoadBalancer;

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
