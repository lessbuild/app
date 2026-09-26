<?php

namespace App\Modules\Deployer\Actions\Account;

use App\Modules\Deployer\Exceptions\TwoFactorOperationException;
use App\Modules\Deployer\Models\User;

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
