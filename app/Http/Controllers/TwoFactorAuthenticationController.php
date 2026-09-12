<?php

namespace App\Http\Controllers;

use App\Actions\Account\BeginTwoFactorSetupAction;
use App\Actions\Account\CancelTwoFactorSetupAction;
use App\Actions\Account\ConfirmTwoFactorAction;
use App\Actions\Account\DisableTwoFactorAction;
use App\Actions\Account\RegenerateTwoFactorRecoveryCodesAction;
use App\Exceptions\TwoFactorOperationException;
use App\Http\Requests\ConfirmTwoFactorRequest;
use App\Http\Requests\DisableTwoFactorRequest;
use App\Http\Requests\EnableTwoFactorRequest;
use App\Http\Requests\RegenerateTwoFactorRecoveryCodesRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TwoFactorAuthenticationController extends Controller
{
    /**
     * Require any existing local password and initialize an unconfirmed authenticator secret for an account without active two-factor authentication.
     */
    public function enable(EnableTwoFactorRequest $request, BeginTwoFactorSetupAction $begin): RedirectResponse
    {
        try {
            $begin->handle($request->user());
        } catch (TwoFactorOperationException) {
            abort(422);
        }

        return back()->with('two_factor_status', __('Enter a code from your authenticator app to finish setup.'));
    }

    /**
     * Validate an authenticator code against the pending secret and enable two-factor authentication with new recovery-code hashes.
     *
     * @return RedirectResponse The plaintext recovery codes flashed for one-time saving.
     */
    public function confirm(ConfirmTwoFactorRequest $request, ConfirmTwoFactorAction $confirm): RedirectResponse
    {
        $result = $confirm->handle($request->user(), $request->code());

        return back()
            ->with('two_factor_status', __('Two-factor authentication enabled. Save your recovery codes now.'))
            ->with('two_factor_recovery_codes', $result->recoveryCodes);
    }

    /**
     * Validate applicable password and authentication/recovery-code challenges, clear two-factor credentials, and redirect back.
     */
    public function disable(DisableTwoFactorRequest $request, DisableTwoFactorAction $disable): RedirectResponse
    {
        $disable->handle($request->user(), $request->code());

        return back()->with('two_factor_status', __('Two-factor authentication disabled.'));
    }

    /**
     * Clear an unfinished two-factor setup and redirect back; already-enabled accounts receive HTTP 422.
     */
    public function cancel(Request $request, CancelTwoFactorSetupAction $cancel): RedirectResponse
    {
        try {
            $cancel->handle($request->user());
        } catch (TwoFactorOperationException) {
            abort(422);
        }

        return back()->with('two_factor_status', __('Two-factor setup cancelled.'));
    }

    /**
     * Require enabled two-factor authentication and valid password/code challenges before replacing all recovery-code hashes.
     *
     * @return RedirectResponse The new plaintext recovery codes; previous codes cease to work.
     */
    public function regenerateRecoveryCodes(RegenerateTwoFactorRecoveryCodesRequest $request, RegenerateTwoFactorRecoveryCodesAction $regenerate): RedirectResponse
    {
        try {
            $result = $regenerate->handle($request->user(), $request->code());
        } catch (TwoFactorOperationException) {
            abort(422);
        }

        return back()
            ->with('two_factor_status', __('New recovery codes created. Previous codes no longer work.'))
            ->with('two_factor_recovery_codes', $result->recoveryCodes);
    }
}
