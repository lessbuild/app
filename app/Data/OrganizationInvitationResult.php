<?php

namespace App\Data;

use App\Models\OrganizationInvitation;

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
