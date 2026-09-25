<?php

namespace App\Modules\Deployer\Policies;

use App\Modules\Deployer\Models\LoadBalancer;
use App\Modules\Deployer\Models\User;

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
        return $loadBalancer->environment !== null
            && $user->can('view', $loadBalancer->environment)
            && (int) $loadBalancer->organization_id === (int) $user->current_organization_id
            && ($loadBalancer->organization?->permits($user, 'manage') ?? false);
    }
}
