<?php

namespace App\Actions\Account;

use App\Models\User;
use App\Services\AccountAuthentication;
use Illuminate\Contracts\Session\Session;
use Illuminate\Validation\ValidationException;

class ConfirmPasswordAction
{
    public function __construct(
        private readonly AccountAuthentication $authentication,
        private readonly Session $session,
    ) {}

    /** Verify the local password and record the existing session confirmation timestamp. */
    public function handle(User $user, string $password): void
    {
        if (! $this->authentication->validatePassword($user, $password)) {
            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        $this->session->put('auth.password_confirmed_at', now()->timestamp);
    }
}
