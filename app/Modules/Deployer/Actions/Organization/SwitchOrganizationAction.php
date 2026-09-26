<?php

namespace App\Modules\Deployer\Actions\Organization;

use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\User;

class SwitchOrganizationAction
{
    /**
     * Select an already-authorized workspace for the actor.
     */
    public function handle(User $actor, Organization $organization): void
    {
        $actor->update(['current_organization_id' => $organization->id]);
    }
}
