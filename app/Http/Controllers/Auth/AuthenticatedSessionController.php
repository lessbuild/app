<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\LoginRequest;
use App\Models\SignInEvent;
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
    public function store(LoginRequest $request, SignInRecorder $signIns): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();
        if ($request->user()->twoFactorEnabled()) {
            $request->session()->put([
                'two_factor_login_user_id' => $request->user()->id,
                'two_factor_login_remember' => $request->boolean('remember'),
                'two_factor_login_method' => SignInEvent::METHOD_PASSWORD,
            ]);
            Auth::guard('web')->logout();

            return redirect()->route('two-factor.login');
        }
        $signIns->record($request->user(), SignInEvent::METHOD_PASSWORD, $request);

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
