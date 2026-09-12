<?php

namespace App\Actions\Account;

use App\Exceptions\TwoFactorOperationException;
use App\Models\User;

class CancelTwoFactorSetupAction
{
    /**
     * Clear an unfinished authenticator setup without touching an active two-factor configuration.
     *
     * @param  User  $user  The authenticated account cancelling setup.
     *
     * @throws TwoFactorOperationException If two-factor authentication is already active.
     */
    public function handle(User $user): void
    {
        if ($user->twoFactorEnabled()) {
            throw new TwoFactorOperationException;
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }
}
