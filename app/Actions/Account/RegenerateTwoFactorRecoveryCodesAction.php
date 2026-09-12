<?php

namespace App\Actions\Account;

use App\Data\TwoFactorRecoveryCodesResult;
use App\Exceptions\TwoFactorOperationException;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\TwoFactorAuthentication;
use Illuminate\Validation\ValidationException;

class RegenerateTwoFactorRecoveryCodesAction
{
    public function __construct(
        private readonly TwoFactorAuthentication $twoFactor,
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * Verify without consuming a code, replace all recovery-code hashes, and return plaintext codes once.
     *
     * @param  User  $user  The authenticated account regenerating recovery codes.
     * @param  string  $code  The validated authenticator or recovery code.
     *
     * @throws TwoFactorOperationException If two-factor became disabled after request validation.
     * @throws ValidationException If the authentication or recovery code is invalid.
     */
    public function handle(User $user, string $code): TwoFactorRecoveryCodesResult
    {
        if (! $user->twoFactorEnabled()) {
            throw new TwoFactorOperationException;
        }

        if (! $this->twoFactor->verifyUser($user, $code, consumeRecoveryCode: false)) {
            throw ValidationException::withMessages([
                'code' => __('The authentication or recovery code is invalid.'),
            ])->errorBag('twoFactor');
        }

        $recoveryCodes = $this->twoFactor->generateRecoveryCodes();
        $user->forceFill([
            'two_factor_recovery_codes' => $this->twoFactor->recoveryCodeHashes($recoveryCodes),
        ])->save();
        $this->activity->recordAccount($user, 'Two-factor recovery codes were regenerated.');

        return new TwoFactorRecoveryCodesResult($recoveryCodes);
    }
}
