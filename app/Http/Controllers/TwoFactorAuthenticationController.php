<?php

namespace App\Http\Controllers;

use App\Services\ActivityRecorder;
use App\Services\TwoFactorAuthentication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TwoFactorAuthenticationController extends Controller
{
    public function enable(Request $request, TwoFactorAuthentication $twoFactor): RedirectResponse
    {
        abort_if($request->user()->twoFactorEnabled(), 422);
        $this->validatePassword($request);
        $request->user()->forceFill([
            'two_factor_secret' => $twoFactor->generateSecret(),
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return back()->with('two_factor_status', __('Enter a code from your authenticator app to finish setup.'));
    }

    public function confirm(
        Request $request,
        TwoFactorAuthentication $twoFactor,
        ActivityRecorder $activity,
    ): RedirectResponse {
        $data = $request->validateWithBag('twoFactor', ['code' => ['required', 'string', 'max:20']]);
        $user = $request->user();
        if (blank($user->two_factor_secret) || ! $twoFactor->verifyCode($user->two_factor_secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => __('The authentication code is invalid.')])->errorBag('twoFactor');
        }

        $recoveryCodes = $twoFactor->generateRecoveryCodes();
        $user->forceFill([
            'two_factor_recovery_codes' => $twoFactor->recoveryCodeHashes($recoveryCodes),
            'two_factor_confirmed_at' => now(),
        ])->save();
        $activity->recordAccount($user, 'Two-factor authentication was enabled.');

        return back()
            ->with('two_factor_status', __('Two-factor authentication enabled. Save your recovery codes now.'))
            ->with('two_factor_recovery_codes', $recoveryCodes);
    }

    public function disable(
        Request $request,
        TwoFactorAuthentication $twoFactor,
        ActivityRecorder $activity,
    ): RedirectResponse {
        $this->validatePassword($request, withCode: true);
        if (! $twoFactor->verifyUser($request->user(), (string) $request->input('code'))) {
            throw ValidationException::withMessages(['code' => __('The authentication or recovery code is invalid.')])->errorBag('twoFactor');
        }

        $request->user()->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
        $activity->recordAccount($request->user(), 'Two-factor authentication was disabled.');

        return back()->with('two_factor_status', __('Two-factor authentication disabled.'));
    }

    public function cancel(Request $request): RedirectResponse
    {
        abort_if($request->user()->twoFactorEnabled(), 422);
        $request->user()->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return back()->with('two_factor_status', __('Two-factor setup cancelled.'));
    }

    public function regenerateRecoveryCodes(
        Request $request,
        TwoFactorAuthentication $twoFactor,
        ActivityRecorder $activity,
    ): RedirectResponse {
        abort_unless($request->user()->twoFactorEnabled(), 422);
        $this->validatePassword($request, withCode: true);
        if (! $twoFactor->verifyUser($request->user(), (string) $request->input('code'), consumeRecoveryCode: false)) {
            throw ValidationException::withMessages(['code' => __('The authentication or recovery code is invalid.')])->errorBag('twoFactor');
        }

        $recoveryCodes = $twoFactor->generateRecoveryCodes();
        $request->user()->forceFill([
            'two_factor_recovery_codes' => $twoFactor->recoveryCodeHashes($recoveryCodes),
        ])->save();
        $activity->recordAccount($request->user(), 'Two-factor recovery codes were regenerated.');

        return back()
            ->with('two_factor_status', __('New recovery codes created. Previous codes no longer work.'))
            ->with('two_factor_recovery_codes', $recoveryCodes);
    }

    private function validatePassword(Request $request, bool $withCode = false): void
    {
        $rules = [];
        if ($request->user()->hasLocalPassword()) {
            $rules['current_password'] = ['required', 'current_password'];
        }
        if ($withCode) {
            $rules['code'] = ['required', 'string', 'max:64'];
        }
        $request->validateWithBag('twoFactor', $rules);
    }
}
