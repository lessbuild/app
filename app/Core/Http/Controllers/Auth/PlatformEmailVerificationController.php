<?php

namespace App\Core\Http\Controllers\Auth;

use App\Core\Models\PlatformUser;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class PlatformEmailVerificationController
{
    public function notice(): View|RedirectResponse
    {
        $user = Auth::guard('platform')->user();
        abort_unless($user instanceof PlatformUser, 401);

        if ($user->hasVerifiedEmail()) {
            return redirect()->route('core.home');
        }

        return view('core::auth.verify-email');
    }

    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = Auth::guard('platform')->user();
        abort_unless(
            $user instanceof PlatformUser
                && hash_equals((string) $user->getKey(), $id)
                && hash_equals(sha1($user->getEmailForVerification()), $hash),
            403,
        );

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return redirect()->route('core.home')->with('status', __('Your email address has been verified.'));
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $request->user('platform');
        abort_unless($user instanceof PlatformUser, 401);

        if ($user->hasVerifiedEmail()) {
            return back()->with('status', __('Your email address is already verified.'));
        }

        $user->sendEmailVerificationNotification();

        return back()->with('status', __('A fresh verification link has been sent.'));
    }
}
