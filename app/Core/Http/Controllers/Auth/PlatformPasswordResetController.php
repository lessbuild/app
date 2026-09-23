<?php

namespace App\Core\Http\Controllers\Auth;

use App\Core\Models\PlatformUser;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class PlatformPasswordResetController
{
    public function create(): View
    {
        return view('core::auth.forgot-password');
    }

    public function send(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
        ]);

        Password::broker('platform_users')->sendResetLink(['email' => $data['email']]);

        return back()->with('status', __('If an account exists for that email, a password reset link has been sent.'));
    }

    public function edit(Request $request, string $token): View
    {
        return view('core::auth.reset-password', [
            'token' => $token,
            'email' => is_string($request->query('email')) ? $request->query('email') : null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $status = Password::broker('platform_users')->reset(
            [
                'email' => $data['email'],
                'password' => $data['password'],
                'token' => $data['token'],
            ],
            function (PlatformUser $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'password_set_at' => now(),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        return $status === PasswordBroker::PASSWORD_RESET
            ? redirect()->route('platform.login')->with('status', __($status))
            : back()->withInput(['email' => $data['email']])
                ->withErrors(['email' => __('This password reset link is invalid or has expired.')]);
    }
}
