<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Account\CompletePasswordLoginAction;
use App\Data\PasswordLoginResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\LoginRequest;
use App\Models\SignInEvent;
use App\Models\User;
use App\Services\SignInRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     *
     * @return View
     */
    public function create(): View
    {
        return view('scenes.auth.login');
    }

    /**
     * Handle an incoming authentication request.
     *
     * @return RedirectResponse
     *
     * @throws ValidationException
     */
    public function store(
        LoginRequest $request,
        CompletePasswordLoginAction $complete,
        SignInRecorder $signIns,
    ): RedirectResponse {
        $request->authenticate();

        /** @var User $user */
        $user = $request->user();
        $result = $complete->handle($user, $request->boolean('remember'));
        if ($result->status === PasswordLoginResult::TWO_FACTOR_REQUIRED) {
            return redirect()->route('two-factor.login');
        }

        $signIns->record($result->user, SignInEvent::METHOD_PASSWORD, $request);

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Destroy an authenticated session.
     *
     * @return RedirectResponse
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
