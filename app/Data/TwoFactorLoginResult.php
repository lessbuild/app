<?php

namespace App\Data;

use App\Models\User;

class TwoFactorLoginResult
{
    /** Carry the authenticated account and the original sign-in method after a pending challenge succeeds. */
    public function __construct(
        public readonly User $user,
        public readonly string $method,
    ) {}
}
