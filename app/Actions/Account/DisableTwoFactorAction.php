<?php

namespace App\Actions\Account;

use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\TwoFactorAuthentication;
use Illuminate\Validation\ValidationException;

class DisableTwoFactorAction
{
    public function __construct(
        private readonly TwoFactorAuthentication $twoFactor,
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * Verify and consume an authentication/recovery code, clear two-factor credentials, and record activity.
     *
     * @param  User  $user  The authenticated account disabling two-factor authentication.
     * @param  string  $code  The validated authenticator or recovery code.
     *
     * @throws ValidationException If the authentication or recovery code is invalid.
     */
    public function handle(User $user, string $code): void
    {
        if (! $this->twoFactor->verifyUser($user, $code)) {
            throw ValidationException::withMessages([
                'code' => __('The authentication or recovery code is invalid.'),
            ])->errorBag('twoFactor');
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
        $this->activity->recordAccount($user, 'Two-factor authentication was disabled.');
    }
}
