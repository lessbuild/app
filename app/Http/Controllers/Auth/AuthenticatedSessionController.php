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
    public function create()
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
    public function store(LoginRequest $request, SignInRecorder $signIns)
    {
        $request->authenticate();

        $request->session()->regenerate();
        $signIns->record($request->user(), SignInEvent::METHOD_PASSWORD, $request);

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Destroy an authenticated session.
     *
     * @return RedirectResponse
     */
    public function destroy(Request $request)
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
