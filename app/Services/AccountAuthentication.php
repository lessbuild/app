<?php

namespace App\Services;

use Illuminate\Auth\AuthManager;

class AccountAuthentication
{
    public function __construct(private readonly AuthManager $auth) {}

    /** End the current authenticated browser session using Laravel's existing session-guard behavior. */
    public function logout(): void
    {
        $this->auth->guard('web')->logout();
    }

    /** Invalidate other authenticated browser devices using Laravel's existing session-guard behavior. */
    public function logoutOtherDevices(string $password): void
    {
        $this->auth->guard('web')->logoutOtherDevices($password);
    }
}
