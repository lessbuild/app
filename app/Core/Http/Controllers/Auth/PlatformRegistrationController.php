<?php

namespace App\Core\Http\Controllers\Auth;

use App\Core\Services\Auth\PlatformAuthenticationSessions;
use App\Core\Services\Auth\RegisterPlatformAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

final class PlatformRegistrationController
{
    public function create(Request $request, RegisterPlatformAccount $registration): View|RedirectResponse
    {
        if (Auth::guard('platform')->check()) {
            return redirect()->route('core.home');
        }

        abort_unless($registration->available(), 404);

        return view('core::auth.register');
    }

    public function store(
        Request $request,
        RegisterPlatformAccount $registration,
        PlatformAuthenticationSessions $sessions,
    ): Response {
        if (Auth::guard('platform')->check()) {
            return redirect()->route('core.home');
        }

        abort_unless($registration->available(), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'min:12', 'max:1024', 'confirmed'],
            'workspace_name' => ['required', 'string', 'max:120'],
        ]);

        $user = $registration->handle(
            $data['name'],
            $data['email'],
            $data['password'],
            $data['workspace_name'],
        );

        Auth::guard('platform')->login($user);
        $request->session()->regenerate();
        $sessions->begin($user, $request, remember: false);
        $user->sendEmailVerificationNotification();

        return redirect()->route('platform.verification.notice')->with(
            'status',
            __('Your workspace is ready. Verify your email address to complete account setup.'),
        );
    }
}
