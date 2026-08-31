<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
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
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        // Deliberately ignore the broker result so registered and unregistered
        // addresses receive the same public response.
        Password::sendResetLink($request->only('email'));

        return back()->with(
            'status',
            __('If an account exists for that email, a password reset link has been sent.'),
        );
    }
}
