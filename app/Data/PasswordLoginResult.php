<?php

namespace App\Data;

use App\Models\User;

class PasswordLoginResult
{
    public const AUTHENTICATED = 'authenticated';

    public const TWO_FACTOR_REQUIRED = 'two_factor_required';

    /** Carry the authenticated account and the next step after password verification. */
    public function __construct(
        public readonly User $user,
        public readonly string $status,
    ) {}
}
