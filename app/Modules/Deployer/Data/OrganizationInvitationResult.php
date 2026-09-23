<?php

namespace App\Modules\Deployer\Data;

use App\Modules\Deployer\Models\OrganizationInvitation;

class OrganizationInvitationResult
{
    /**
     * Carry the persisted invitation and its one-time plaintext token to the notification boundary.
     */
    public function __construct(
        public readonly OrganizationInvitation $invitation,
        public readonly string $email,
        public readonly string $token,
    ) {}
}
