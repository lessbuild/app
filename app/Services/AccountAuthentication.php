<?php

namespace App\Services;

use Illuminate\Auth\AuthManager;

class AccountAuthentication
{
    public function __construct(private readonly AuthManager $auth) {}

    /** Invalidate other authenticated browser devices using Laravel's existing session-guard behavior. */
    public function logoutOtherDevices(string $password): void
    {
        $this->auth->guard('web')->logoutOtherDevices($password);
    }
}
