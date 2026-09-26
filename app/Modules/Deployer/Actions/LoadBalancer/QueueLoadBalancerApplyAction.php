<?php

namespace App\Modules\Deployer\Actions\LoadBalancer;

use App\Modules\Deployer\Exceptions\LoadBalancerRemovalConflictException;
use App\Modules\Deployer\Jobs\ApplyLoadBalancerJob;
use App\Modules\Deployer\Models\LoadBalancer;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

class QueueLoadBalancerApplyAction
{
    /**
     * Record pending configuration, queue the existing Caddy apply job, and retain a safe failure state if dispatch fails.
     */
    public function handle(LoadBalancer $loadBalancer, ?Closure $mutate = null): void
    {
        DB::connection('deployer')->transaction(function () use ($loadBalancer, $mutate): void {
            $current = LoadBalancer::query()->lockForUpdate()->findOrFail($loadBalancer->id);
            $this->assertCanApply($current);
            $mutate?->__invoke($current);
            $current->forceFill(['status' => 'pending', 'last_error' => null])->save();
        });

        try {
            ApplyLoadBalancerJob::dispatch($loadBalancer->id);
        } catch (Throwable $exception) {
            LoadBalancer::query()
                ->whereKey($loadBalancer->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'failed',
                    'last_error' => 'Load-balancer configuration could not be queued.',
                    'updated_at' => now(),
                ]);

            throw $exception;
        }
    }

    /** Reject configuration changes while remote deletion is pending or has failed. */
    public function assertCanApply(LoadBalancer $loadBalancer): void
    {
        if (in_array($loadBalancer->status, ['removing', 'removal_failed'], true)) {
            throw new LoadBalancerRemovalConflictException('Retry or finish load-balancer removal before changing its configuration.');
        }
    }
}
