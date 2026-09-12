<?php

namespace App\Actions\Account;

use App\Exceptions\TwoFactorOperationException;
use App\Models\User;
use App\Services\TwoFactorAuthentication;

class BeginTwoFactorSetupAction
{
    public function __construct(private readonly TwoFactorAuthentication $twoFactor) {}

    /**
     * Generate and persist a pending authenticator secret while clearing any stale recovery setup.
     *
     * @param  User  $user  The authenticated account beginning setup.
     *
     * @throws TwoFactorOperationException If two-factor authentication became active after request validation.
     */
    public function handle(User $user): void
    {
        if ($user->twoFactorEnabled()) {
            throw new TwoFactorOperationException;
        }

        $user->forceFill([
            'two_factor_secret' => $this->twoFactor->generateSecret(),
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }
}
