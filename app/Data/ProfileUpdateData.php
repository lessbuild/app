<?php

namespace App\Data;

class ProfileUpdateData
{
    /** Carry the validated profile fields and optional current-password challenge to the profile operation. */
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly ?string $currentPassword = null,
    ) {}
}
