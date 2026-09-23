<?php

namespace App\Core\Http\Middleware;

use App\Core\Models\PlatformUser;
use App\Core\Services\Auth\ProductAuthentication;
use App\Core\Services\Identity\EnsurePlatformProductPrincipal;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class RedirectProductGuestToPlatform
{
    public function __construct(
        private readonly ProductAuthentication $authentication,
        private readonly EnsurePlatformProductPrincipal $productPrincipals,
    ) {}

    public function handle(Request $request, Closure $next, string $product): Response
    {
        $platformUser = Auth::guard('platform')->user();

        if ($platformUser instanceof PlatformUser) {
            abort_unless($platformUser->status === 'active', 401);

            if ($this->authentication->usesCoreAuthority($product)) {
                $this->productPrincipals->handle($product, $platformUser);
            }

            abort_if($this->authentication->resolvePrincipal($product, $platformUser) === null, 403);

            return redirect()->route($this->authentication->dashboardRoute($product));
        }

        return redirect()->to($this->authentication->platformLoginUrl($request, $product));
    }
}
