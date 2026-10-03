<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Models\LoadBalancerNode;
use App\Models\User;
use App\Services\Infrastructure\LoadBalancerChanges;
use Illuminate\Support\Facades\Gate;

final class DeleteLoadBalancerNode
{
    /**
     * Create a new DeleteLoadBalancerNode instance.
     *
     * Removes a server from behind a load balancer.
     *
     * @param  LoadBalancerChanges  $changes  Re-applies the load balancer's configuration.
     */
    public function __construct(private readonly LoadBalancerChanges $changes) {}

    /**
     * Stop sending traffic to a server.
     *
     * @param  User  $actor
     * @param  LoadBalancerNode  $node
     * @return void
     */
    public function handle(User $actor, LoadBalancerNode $node): void
    {
        Gate::forUser($actor)->authorize('update', $node->loadBalancer);
        $this->changes->apply($node->loadBalancer, fn () => $node->delete());
    }
}
