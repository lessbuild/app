<?php

namespace App\Modules\Deployer\Actions\LoadBalancer;

use App\Modules\Deployer\Jobs\RemoveLoadBalancerJob;
use App\Modules\Deployer\Models\LoadBalancer;

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
