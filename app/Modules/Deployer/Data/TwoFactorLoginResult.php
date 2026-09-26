<?php

namespace App\Modules\Deployer\Data;

use App\Modules\Deployer\Models\User;

class TwoFactorLoginResult
{
    /** Carry the authenticated account and the original sign-in method after a pending challenge succeeds. */
    public function __construct(
        public readonly User $user,
        public readonly string $method,
    ) {}
}
