<?php

namespace App\Modules\Deployer\Data;

class ResetPasswordData
{
    /** Carry validated reset credentials to the password-broker operation. */
    public function __construct(
        public readonly string $token,
        public readonly string $email,
        public readonly string $password,
    ) {}
}
