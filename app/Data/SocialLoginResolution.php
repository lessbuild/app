<?php

namespace App\Data;

use App\Models\User;

class SocialLoginResolution
{
    public const RESOLVED = 'resolved';

    public const EXISTING_EMAIL = 'existing_email';

    public const CLOSED = 'closed';

    /**
     * Carry the named guest social-login outcome and its resolved account when one exists.
     *
     * @param  string  $status  One of the resolution constants above.
     */
    public function __construct(
        public readonly string $status,
        public readonly ?User $user = null,
    ) {}
}
