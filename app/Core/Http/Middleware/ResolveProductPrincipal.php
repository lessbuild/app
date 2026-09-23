<?php

namespace App\Core\Http\Middleware;

use App\Core\Models\PlatformUser;
use App\Core\Services\Identity\ProductPrincipalRegistry;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class ResolveProductPrincipal
{
    public function __construct(private readonly ProductPrincipalRegistry $principals) {}

    public function handle(Request $request, Closure $next, string $product): Response
    {
        $platformUser = Auth::guard('platform')->user();

        abort_unless($platformUser instanceof PlatformUser && $platformUser->status === 'active', 401);

        $principal = $this->principals->resolve($product, $platformUser);

        abort_unless($principal !== null, 403);

        $previousGuard = (string) config('auth.defaults.guard', 'web');
        $guard = Auth::guard($product);

        abort_unless($guard instanceof SessionGuard, 500, 'Product guards must use Laravel session guards.');

        Auth::shouldUse($product);
        $guard->setUser($principal);
        $request->attributes->set('platform_user', $platformUser);

        try {
            return $next($request);
        } finally {
            $guard->forgetUser();
            Auth::shouldUse($previousGuard);
        }
    }
}
