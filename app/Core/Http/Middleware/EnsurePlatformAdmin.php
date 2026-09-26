<?php

namespace App\Core\Http\Middleware;

use App\Core\Models\PlatformUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admits platform administrators who have a second factor and a recent confirmation.
 * Use `platform.admin:confirming` on the confirmation routes themselves.
 */
final class EnsurePlatformAdmin
{
    public const SESSION_KEY = 'platform_admin_confirmation';

    public const INTENDED_KEY = 'platform_admin_intended';

    public const CONFIRMATION_SECONDS = 900;

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $user = $request->user('platform');
        // Non-admins get a plain 404 so the admin area is not advertised.
        abort_unless($user instanceof PlatformUser && $user->isPlatformAdmin(), 404);

        if (! $user->hasSecondFactor()) {
            return redirect()->route('platform.account.security')
                ->with('status', __('Add an authenticator app or passkey before opening platform administration.'));
        }

        if ($mode !== 'confirming' && ! self::recentlyConfirmed($request, $user)) {
            if ($request->isMethod('GET')) {
                $request->session()->put(self::INTENDED_KEY, $request->fullUrl());
            }

            return redirect()->route('core.admin.confirm');
        }

        return $next($request);
    }

    public static function recentlyConfirmed(Request $request, PlatformUser $user): bool
    {
        $confirmation = $request->session()->get(self::SESSION_KEY);

        return is_array($confirmation)
            && ($confirmation['user'] ?? null) === (string) $user->getKey()
            && is_int($confirmation['at'] ?? null)
            && now()->getTimestamp() - $confirmation['at'] < self::CONFIRMATION_SECONDS;
    }
}
