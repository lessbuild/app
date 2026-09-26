<?php

namespace App\Modules\Deployer\Policies;

use App\Modules\Deployer\Models\AlertOutboundDelivery;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\Core\DeployerProjectAccess;

class AlertOutboundDeliveryPolicy
{
    /** Allow a manager to replay one failed delivery for a destination in the user's current workspace. */
    public function retry(User $user, AlertOutboundDelivery $delivery): bool
    {
        $destination = $delivery->destination;
        $organization = $destination?->organization;

        return $destination !== null && $organization !== null
            && (int) $delivery->organization_id === (int) $user->current_organization_id
            && (int) $destination->organization_id === (int) $delivery->organization_id
            && $organization->permits($user, 'manage')
            && app(DeployerProjectAccess::class)->canAccessWorkspaceResources($user);
    }
}
