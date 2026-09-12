<?php

namespace App\Actions\Account;

use App\Data\TwoFactorConfirmationResult;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\TwoFactorAuthentication;
use Illuminate\Validation\ValidationException;

class ConfirmTwoFactorAction
{
    public function __construct(
        private readonly TwoFactorAuthentication $twoFactor,
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * Verify a pending authenticator code, persist hashed recovery codes, and return their plaintext values once.
     *
     * @param  User  $user  The authenticated account completing setup.
     * @param  string  $code  The validated authenticator code.
     *
     * @throws ValidationException If no pending secret exists or the code is invalid.
     */
    public function handle(User $user, string $code): TwoFactorConfirmationResult
    {
        if (blank($user->two_factor_secret) || ! $this->twoFactor->verifyCode($user->two_factor_secret, $code)) {
            throw ValidationException::withMessages([
                'code' => __('The authentication code is invalid.'),
            ])->errorBag('twoFactor');
        }

        $recoveryCodes = $this->twoFactor->generateRecoveryCodes();
        $user->forceFill([
            'two_factor_recovery_codes' => $this->twoFactor->recoveryCodeHashes($recoveryCodes),
            'two_factor_confirmed_at' => now(),
        ])->save();
        $this->activity->recordAccount($user, 'Two-factor authentication was enabled.');

        return new TwoFactorConfirmationResult($recoveryCodes);
    }
}
