<?php

namespace App\Data;

class RegistrationData
{
    /** Carry the validated account fields and the optional invitation protocol token to registration. */
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $password,
        public readonly string $invitationToken = '',
    ) {}
}
