<?php

namespace App\Modules\Deployer\Http\Controllers\Auth;

use App\Modules\Deployer\Actions\Account\SendPasswordResetLinkAction;
use App\Modules\Deployer\Http\Controllers\Controller;
use App\Modules\Deployer\Http\Requests\SendPasswordResetLinkRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('scenes.auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     *
     *
     * @throws ValidationException
     */
    public function store(SendPasswordResetLinkRequest $request, SendPasswordResetLinkAction $send): RedirectResponse
    {
        // Deliberately ignore the broker result so registered and unregistered
        // addresses receive the same public response.
        $send->handle($request->email());

        return back()->with(
            'status',
            __('If an account exists for that email, a password reset link has been sent.'),
        );
    }
}
