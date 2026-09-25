<?php

namespace App\Modules\Deployer\Actions\LoadBalancer;

use App\Modules\Deployer\Jobs\RemoveLoadBalancerJob;
use App\Modules\Deployer\Models\LoadBalancer;
use Throwable;

class QueueLoadBalancerRemovalAction
{
    /** Queue idempotent remote cleanup and retain a safe, visible state when dispatch fails. */
    public function handle(int $serverId, int $loadBalancerId): void
    {
        try {
            RemoveLoadBalancerJob::dispatch($serverId, $loadBalancerId);
        } catch (Throwable $exception) {
            LoadBalancer::query()
                ->whereKey($loadBalancerId)
                ->where('server_id', $serverId)
                ->where('status', 'removing')
                ->update([
                    'status' => 'removal_failed',
                    'last_error' => 'Remote load-balancer cleanup could not be queued. Retry removal from Deployer.',
                    'updated_at' => now(),
                ]);

            throw $exception;
        }
    }
}
