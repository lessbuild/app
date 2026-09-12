<?php

namespace App\Policies;

use App\Models\LoadBalancer;
use App\Models\User;

class LoadBalancerPolicy
{
    /**
     * Allow a manager in the selected workspace to create a load balancer.
     */
    public function create(User $user): bool
    {
        return $user->currentOrganization?->permits($user, 'manage') ?? false;
    }

    /**
     * Allow a manager in the load balancer's selected workspace to manage it.
     */
    public function manage(User $user, LoadBalancer $loadBalancer): bool
    {
        return (int) $loadBalancer->organization_id === (int) $user->current_organization_id
            && ($loadBalancer->organization?->permits($user, 'manage') ?? false);
    }
}
