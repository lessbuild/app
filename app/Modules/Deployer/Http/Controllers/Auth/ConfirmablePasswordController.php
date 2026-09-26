<?php

namespace App\Modules\Deployer\Http\Controllers\Auth;

use App\Modules\Deployer\Actions\Account\ConfirmPasswordAction;
use App\Modules\Deployer\Http\Controllers\Controller;
use App\Modules\Deployer\Http\Requests\ConfirmPasswordRequest;
use App\Providers\RouteServiceProvider;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ConfirmablePasswordController extends Controller
{
    /**
     * Render local-password confirmation, or redirect social-only accounts to their account settings.
     */
    public function show(Request $request): View|RedirectResponse
    {
        if (! $request->user()->hasLocalPassword()) {
            return redirect()->route('account.index')->with(
                'social_error',
                __('This account does not have a local password to confirm.'),
            );
        }

        return view('scenes.auth.confirm-password');
    }

    /** Confirm the user's password. */
    public function store(ConfirmPasswordRequest $request, ConfirmPasswordAction $confirm): RedirectResponse
    {
        if (! $request->user()->hasLocalPassword()) {
            return redirect()->route('account.index')->with(
                'social_error',
                __('This account does not have a local password to confirm.'),
            );
        }

        $confirm->handle($request->user(), $request->password());

        return redirect()->intended(RouteServiceProvider::HOME);
    }
}
