<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Account\VerifyEmailAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(EmailVerificationRequest $request, VerifyEmailAction $verify): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (! $verify->handle($user)) {
            return redirect()->route('dashboard');
        }

        return redirect()->route('dashboard')->with('success', __('Email address verified.'));
    }
}
