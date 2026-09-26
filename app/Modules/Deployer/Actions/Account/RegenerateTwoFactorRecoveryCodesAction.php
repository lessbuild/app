<?php

namespace App\Modules\Deployer\Actions\Account;

use App\Modules\Deployer\Data\TwoFactorRecoveryCodesResult;
use App\Modules\Deployer\Exceptions\TwoFactorOperationException;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\ActivityRecorder;
use App\Modules\Deployer\Services\TwoFactorAuthentication;
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
