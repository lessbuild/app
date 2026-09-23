<?php

namespace App\Core\Http\Controllers\Auth;

use App\Core\Models\PlatformUser;
use App\Core\Services\Auth\PlatformAuthenticationSessions;
use App\Core\Services\Auth\PlatformRedirectTarget;
use App\Core\Services\Auth\PlatformSsoHandoff;
use App\Core\Services\Auth\RegisterPlatformAccount;
use App\Core\Services\Auth\VerifyPlatformTwoFactorCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

final class PlatformSessionController
{
    public function create(
        Request $request,
        PlatformRedirectTarget $redirects,
        PlatformSsoHandoff $handoff,
        RegisterPlatformAccount $registration,
    ): View|Response {
        $requestedTarget = $request->query('return_to');
        $target = $redirects->resolve(is_string($requestedTarget) ? $requestedTarget : null, $request);

        $user = Auth::guard('platform')->user();

        if ($user instanceof PlatformUser) {
            return $handoff->respond($request, $user, $target ?? route('core.home'));
        }

        return view('core::auth.login', [
            'returnTo' => $target,
            'registrationOpen' => $registration->available(),
        ]);
    }

    public function store(
        Request $request,
        PlatformRedirectTarget $redirects,
        PlatformAuthenticationSessions $sessions,
        PlatformSsoHandoff $handoff,
    ): Response {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:1024'],
            'remember' => ['sometimes', 'boolean'],
            'return_to' => ['nullable', 'string', 'max:2048'],
        ]);

        $guard = Auth::guard('platform');
        $provider = $guard->getProvider();
        $user = $provider->retrieveByCredentials(['email' => $data['email']]);

        if (! $user instanceof PlatformUser
            || ! $provider->validateCredentials($user, ['password' => $data['password']])) {
            throw ValidationException::withMessages(['email' => __('These credentials do not match our records.')]);
        }

        $previousUser = $guard->user();
        if ($previousUser instanceof PlatformUser) {
            $sessions->revoke($previousUser, $request);
            $guard->logout();
        }

        $returnTo = $redirects->resolve($data['return_to'] ?? null, $request);

        if ($user->twoFactorEnabled()) {
            $request->session()->regenerate();
            $request->session()->put([
                'platform.auth.two_factor_user_id' => $user->getKey(),
                'platform.auth.remember' => (bool) ($data['remember'] ?? false),
                'platform.auth.return_to' => $returnTo,
            ]);

            return to_route('platform.two-factor.create');
        }

        $guard->login($user, (bool) ($data['remember'] ?? false));
        $request->session()->regenerate();
        $sessions->begin($user, $request, (bool) ($data['remember'] ?? false));

        return $handoff->respond($request, $user, $returnTo ?? route('core.home'));
    }

    public function createTwoFactorChallenge(Request $request): View|RedirectResponse
    {
        if (Auth::guard('platform')->check()) {
            return redirect()->route('core.home');
        }

        if (! $request->session()->has('platform.auth.two_factor_user_id')) {
            return redirect()->route('platform.login');
        }

        return view('core::auth.two-factor-challenge');
    }

    public function storeTwoFactorChallenge(
        Request $request,
        VerifyPlatformTwoFactorCode $verifyCode,
        PlatformAuthenticationSessions $sessions,
        PlatformSsoHandoff $handoff,
    ): Response {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        $userId = $request->session()->get('platform.auth.two_factor_user_id');
        $user = is_string($userId)
            ? PlatformUser::query()->whereKey($userId)->where('status', 'active')->first()
            : null;

        if (! $user || ! $user->twoFactorEnabled()) {
            $request->session()->forget([
                'platform.auth.two_factor_user_id',
                'platform.auth.remember',
                'platform.auth.return_to',
            ]);

            return redirect()->route('platform.login')->withErrors([
                'code' => __('This sign-in challenge has expired. Please sign in again.'),
            ]);
        }

        if (! $verifyCode->handle($user, $data['code'])) {
            throw ValidationException::withMessages([
                'code' => __('The authentication or recovery code is invalid.'),
            ]);
        }

        $returnTo = $request->session()->pull('platform.auth.return_to');
        $remember = (bool) $request->session()->pull('platform.auth.remember', false);
        $request->session()->forget('platform.auth.two_factor_user_id');

        Auth::guard('platform')->login($user, $remember);
        $request->session()->regenerate();
        $sessions->begin($user, $request, $remember);

        return $handoff->respond($request, $user, is_string($returnTo) ? $returnTo : route('core.home'));
    }

    public function destroy(Request $request, PlatformAuthenticationSessions $sessions): RedirectResponse
    {
        $user = Auth::guard('platform')->user();
        if ($user instanceof PlatformUser) {
            $sessions->revoke($user, $request);
        }

        Auth::guard('platform')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('platform.login');
    }
}
