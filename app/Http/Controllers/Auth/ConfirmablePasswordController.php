<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Providers\RouteServiceProvider;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

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
    public function store(Request $request): RedirectResponse
    {
        if (! $request->user()->hasLocalPassword()) {
            return redirect()->route('account.index')->with(
                'social_error',
                __('This account does not have a local password to confirm.'),
            );
        }

        $request->validate(['password' => ['required', 'string']]);

        if (! Auth::guard('web')->validate([
            'email' => $request->user()->email,
            'password' => $request->password,
        ])) {
            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        $request->session()->put('auth.password_confirmed_at', now()->timestamp);

        return redirect()->intended(RouteServiceProvider::HOME);
    }
}
