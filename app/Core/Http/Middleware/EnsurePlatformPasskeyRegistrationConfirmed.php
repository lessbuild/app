<?php

namespace App\Core\Http\Middleware;

use App\Core\Models\PlatformUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePlatformPasskeyRegistrationConfirmed
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('platform');
        $confirmedUserId = $request->session()->get('platform.auth.passkey_registration_user_id');
        $confirmedAt = $request->session()->get('platform.auth.passkey_registration_confirmed_at');
        $confirmed = $user instanceof PlatformUser
            && is_string($confirmedUserId)
            && hash_equals((string) $user->getKey(), $confirmedUserId)
            && is_int($confirmedAt)
            && $confirmedAt >= now()->subMinutes(5)->getTimestamp();

        if (! $confirmed) {
            $request->session()->forget([
                'platform.auth.passkey_registration_user_id',
                'platform.auth.passkey_registration_confirmed_at',
                'passkey.registration_options',
            ]);

            abort(403, __('Confirm your account security details before adding a passkey.'));
        }

        return $next($request);
    }
}
