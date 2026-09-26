<?php

namespace App\Modules\Deployer\Actions\LoadBalancer;

use App\Modules\Deployer\Models\LoadBalancer;
use Illuminate\Support\Facades\DB;

class DeleteLoadBalancerAction
{
    public function __construct(private readonly QueueLoadBalancerRemovalAction $queueRemoval) {}

    /**
     * Persist the removal state before queueing remote cleanup; retain a safe retryable state when dispatch fails.
     */
    public function handle(LoadBalancer $loadBalancer): void
    {
        $dispatch = DB::connection('deployer')->transaction(function () use ($loadBalancer): ?array {
            $current = LoadBalancer::query()->lockForUpdate()->find($loadBalancer->id);

            if ($current === null || $current->status === 'removing') {
                return null;
            }

            $current->forceFill(['status' => 'removing', 'last_error' => null])->save();

            return ['server_id' => (int) $current->server_id, 'load_balancer_id' => (int) $current->id];
        });

        if ($dispatch === null) {
            return;
        }

        $this->queueRemoval->handle($dispatch['server_id'], $dispatch['load_balancer_id']);
    }
}
