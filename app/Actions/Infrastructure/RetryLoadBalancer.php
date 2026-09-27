<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Models\LoadBalancer;
use App\Models\User;
use App\Services\Infrastructure\LoadBalancerChanges;
use Illuminate\Support\Facades\Gate;

final class RetryLoadBalancer
{
    public function __construct(private readonly LoadBalancerChanges $changes, private readonly DeleteLoadBalancer $delete) {}

    /** Write the configuration again, or try the removal again if that's what failed. */
    public function handle(User $actor, LoadBalancer $balancer): void
    {
        Gate::forUser($actor)->authorize('update', $balancer);
        if ($balancer->status === 'removal_failed') {
            $this->delete->handle($actor, $balancer);

            return;
        }
        $this->changes->apply($balancer);
    }
}
