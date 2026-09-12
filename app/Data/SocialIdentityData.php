<?php

namespace App\Data;

class SocialIdentityData
{
    /** Carry the normalized provider identity needed by the guest social-login operation. */
    public function __construct(
        public readonly string $provider,
        public readonly string $providerId,
        public readonly string $email,
        public readonly string $name,
    ) {}
}
