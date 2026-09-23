<?php

namespace App\Modules\Monitor\Http\Controllers\Auth;

use App\Modules\Monitor\Http\Controllers\Controller;
use App\Modules\Monitor\Http\Requests\Auth\ResetPasswordRequest;
use App\Modules\Monitor\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PasswordResetController extends Controller
{
    public function create(): View
    {
        return view('monitor::auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['email' => ['required', 'string', 'email', 'max:254']]);
        Password::sendResetLink(['email' => mb_strtolower(trim($validated['email']))]);

        return back()->with('status', 'If an account matches that email, a password reset link has been sent.');
    }

    public function edit(Request $request, string $token): View
    {
        return view('monitor::auth.reset-password', [
            'token' => $token,
            'email' => is_string($request->query('email')) ? $request->query('email') : '',
        ]);
    }

    public function update(ResetPasswordRequest $request): RedirectResponse
    {
        $status = Password::reset($request->validated(), function (User $user, string $password): void {
            $user->password = $password;
            $user->setRememberToken(Str::random(60));
            $user->save();

            event(new PasswordReset($user));
        });

        return $status === Password::PasswordReset
            ? to_route('monitor.login')->with('status', __($status))
            : back()->withErrors(['email' => __($status)]);
    }
}
