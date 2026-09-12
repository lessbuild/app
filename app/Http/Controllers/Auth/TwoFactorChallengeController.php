<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Account\CompleteTwoFactorLoginAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\TwoFactorChallengeRequest;
use App\Services\SignInRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        TwoFactorChallengeRequest $request,
        CompleteTwoFactorLoginAction $complete,
        SignInRecorder $signIns,
    ): RedirectResponse {
        $result = $complete->handle($request->code());
        $signIns->record($result->user, $result->method, $request);

        return redirect()->intended(route('dashboard'));
    }
}
