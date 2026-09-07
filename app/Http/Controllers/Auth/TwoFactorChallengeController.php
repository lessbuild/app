<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SignInEvent;
use App\Models\User;
use App\Services\SignInRecorder;
use App\Services\TwoFactorAuthentication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TwoFactorChallengeController extends Controller
{
    /**
     * Render a challenge for the session's pending login, or redirect to login when no challenge is pending.
     */
    public function create(Request $request): View|RedirectResponse
    {
        return $request->session()->has('two_factor_login_user_id')
            ? view('scenes.auth.two-factor-challenge')
            : redirect()->route('login');
    }

    /**
     * Validate an authentication or recovery code for the pending user, complete sign-in, and consume challenge state.
     *
     * @return RedirectResponse The intended page after session regeneration and sign-in recording.
     */
    public function store(
        Request $request,
        TwoFactorAuthentication $twoFactor,
        SignInRecorder $signIns,
    ): RedirectResponse {
        $data = $request->validate(['code' => ['required', 'string', 'max:64']]);
        $user = User::query()->find($request->session()->get('two_factor_login_user_id'));
        if (! $user || ! $user->twoFactorEnabled() || ! $twoFactor->verifyUser($user, $data['code'])) {
            throw ValidationException::withMessages(['code' => __('The authentication or recovery code is invalid.')]);
        }

        $remember = (bool) $request->session()->pull('two_factor_login_remember', false);
        $method = (string) $request->session()->pull('two_factor_login_method', SignInEvent::METHOD_PASSWORD);
        $request->session()->forget('two_factor_login_user_id');
        Auth::login($user, $remember);
        $request->session()->regenerate();
        $signIns->record($user, $method, $request);

        return redirect()->intended(route('dashboard'));
    }
}
