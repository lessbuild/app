<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admits platform admins who have a second factor. Anyone else gets a 404, so the admin area isn't advertised. Pair it
 * with `password.confirm` on a 15-minute timeout (the `admin` middleware group does).
 */
final class EnsurePlatformAdmin
{
    /**
     * Let a platform admin with an authenticator app or passkey through; send one without to their security settings.
     *
     * @param  Request  $request
     * @param  Closure(Request): Response  $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_platform_admin, 404);
        if (! $user->hasSecondFactor()) {
            return to_route('settings.security')->with('status', __('Add an authenticator app or a passkey before opening the admin panel.'));
        }

        return $next($request);
    }
}
