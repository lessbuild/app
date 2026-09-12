<?php

namespace App\Actions\Account;

use Illuminate\Contracts\Auth\PasswordBroker;

class SendPasswordResetLinkAction
{
    public function __construct(private readonly PasswordBroker $passwords) {}

    /** Ask Laravel's password broker to send a reset link without exposing broker results to the public response. */
    public function handle(string $email): void
    {
        $this->passwords->sendResetLink(['email' => $email]);
    }
}
