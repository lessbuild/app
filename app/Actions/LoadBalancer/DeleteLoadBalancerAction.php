<?php

namespace App\Actions\LoadBalancer;

use App\Jobs\RemoveLoadBalancerJob;
use App\Models\LoadBalancer;

class DeleteLoadBalancerAction
{
    /**
     * Queue remote configuration removal before deleting the balancer record, preserving job identifiers.
     */
    public function handle(LoadBalancer $loadBalancer): void
    {
        RemoveLoadBalancerJob::dispatch($loadBalancer->server_id, $loadBalancer->id);
        $loadBalancer->delete();
    }
}
