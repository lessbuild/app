<?php

namespace App\Modules\Deployer\Http\Controllers\Auth;

use App\Modules\Deployer\Actions\Account\CompletePasswordLoginAction;
use App\Modules\Deployer\Data\PasswordLoginResult;
use App\Modules\Deployer\Http\Controllers\Controller;
use App\Modules\Deployer\Http\Requests\User\LoginRequest;
use App\Modules\Deployer\Models\SignInEvent;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\SignInRecorder;
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
