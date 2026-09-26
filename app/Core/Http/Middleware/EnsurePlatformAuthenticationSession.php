<?php

namespace App\Core\Http\Middleware;

use App\Core\Models\PlatformUser;
use App\Core\Services\Auth\PlatformAuthenticationSessions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePlatformAuthenticationSession
{
    public function __construct(private readonly PlatformAuthenticationSessions $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('__platform/sso/exchange')) {
            return $next($request);
        }

        if (! Schema::connection('core')->hasTable('platform_auth_sessions')) {
            abort_unless(app()->environment('testing'), 503, 'The Core authentication session migration is required.');

            return $next($request);
        }

        $guard = Auth::guard('platform');
        $user = $guard->user();

        if (! $user instanceof PlatformUser) {
            return $next($request);
        }

        $authSession = $user->status === 'active'
            ? $this->sessions->ensure($user, $request)
            : null;

        if ($authSession !== null) {
            return $next($request);
        }

        $guard->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->routeIs('platform.deletions.progress', 'platform.deletions.recover', 'platform.deletions.retry')) {
            return $next($request);
        }

        return redirect()->route('platform.login', [
            'return_to' => $request->fullUrl(),
        ])->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
