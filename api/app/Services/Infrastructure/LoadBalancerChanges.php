<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Exceptions\StateConflict;
use App\Jobs\Infrastructure\ApplyLoadBalancer;
use App\Models\LoadBalancer;
use Closure;
use Illuminate\Support\Facades\DB;

/** Changes a load balancer (under a row lock) and queues writing the new configuration to its server. */
class LoadBalancerChanges
{
    /**
     * Run a change on the locked load balancer, marks it pending and queues writing its configuration after commit.
     * Load balancers being removed can't change.
     *
     * @param  LoadBalancer  $balancer
     * @param  (Closure(LoadBalancer): mixed)|null  $change
     * @return LoadBalancer
     */
    public function apply(LoadBalancer $balancer, ?Closure $change = null): LoadBalancer
    {
        return DB::transaction(function () use ($balancer, $change): LoadBalancer {
            $locked = LoadBalancer::query()->lockForUpdate()->findOrFail($balancer->id);
            StateConflict::unless(! $locked->isRemoving(), __('This load balancer is being removed.'));
            $change?->__invoke($locked);
            $locked->forceFill(['status' => 'pending', 'last_error' => null])->save();
            ApplyLoadBalancer::dispatch($locked->id)->afterCommit();

            return $locked;
        });
    }
}
