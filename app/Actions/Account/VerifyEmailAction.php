<?php

namespace App\Actions\Account;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Events\Dispatcher;

class VerifyEmailAction
{
    public function __construct(private readonly Dispatcher $events) {}

    /**
     * Mark an unverified address as verified and dispatch Laravel's existing verification event.
     *
     * @return bool Whether the account was unverified when this operation began and should receive the success response.
     */
    public function handle(User $user): bool
    {
        if ($user->hasVerifiedEmail()) {
            return false;
        }

        if ($user->markEmailAsVerified()) {
            $this->events->dispatch(new Verified($user));
        }

        return true;
    }
}
