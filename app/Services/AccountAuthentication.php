<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\AuthManager;

class AccountAuthentication
{
    public function __construct(private readonly AuthManager $auth) {}

    /** End the current authenticated browser session using Laravel's existing session-guard behavior. */
    public function logout(): void
    {
        $this->auth->guard('web')->logout();
    }

    /** Authenticate a user on the existing web guard while preserving the remember-device option. */
    public function login(User $user, bool $remember = false): void
    {
        $this->auth->guard('web')->login($user, $remember);
    }

    /** Validate a local password using Laravel's existing web guard credentials. */
    public function validatePassword(User $user, string $password): bool
    {
        return $this->auth->guard('web')->validate([
            'email' => $user->email,
            'password' => $password,
        ]);
    }

    /** Invalidate other authenticated browser devices using Laravel's existing session-guard behavior. */
    public function logoutOtherDevices(string $password): void
    {
        $this->auth->guard('web')->logoutOtherDevices($password);
    }
}
