<?php

namespace App\Actions\Organization;

use App\Models\Organization;
use App\Models\User;

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
