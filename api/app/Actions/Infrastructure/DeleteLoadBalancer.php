<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Jobs\Infrastructure\RemoveLoadBalancer;
use App\Models\LoadBalancer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DeleteLoadBalancer
{
    /**
     * Remove the proxy configuration from its server, then the load balancer. Also retries a removal that failed.
     *
     * @param  User  $actor
     * @param  LoadBalancer  $balancer
     * @return void
     */
    public function handle(User $actor, LoadBalancer $balancer): void
    {
        Gate::forUser($actor)->authorize('delete', $balancer);
        DB::transaction(function () use ($balancer): void {
            $locked = LoadBalancer::query()->lockForUpdate()->findOrFail($balancer->id);
            if ($locked->status === 'removing') {
                return;
            }
            $locked->forceFill(['status' => 'removing', 'last_error' => null])->save();
            RemoveLoadBalancer::dispatch($locked->id)->afterCommit();
        });
    }
}
