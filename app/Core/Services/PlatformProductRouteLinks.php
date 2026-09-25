<?php

namespace App\Core\Services;

use App\Core\Enums\ProductKey;
use App\Core\Models\PlatformUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/** Build verified links from Core into a product's existing module-owned screen. */
final class PlatformProductRouteLinks
{
    public function __construct(private readonly Request $request) {}

    /** @param array<string, int|string> $parameters */
    public function to(string $product, string $routeName, array $parameters = []): ?string
    {
        if (ProductKey::tryFrom($product) === null || ! Route::has($routeName)) {
            return null;
        }

        $target = route($routeName, $parameters);
        $targetOrigin = $this->origin($target);
        $configuredOrigin = $this->origin((string) config("platform.products.{$product}.url", ''));
        $currentOrigin = $this->origin($this->request->getSchemeAndHttpHost());

        if ($targetOrigin === null || $configuredOrigin === null || $targetOrigin !== $configuredOrigin) {
            return null;
        }

        if ($targetOrigin === $currentOrigin) {
            return $target;
        }

        if (! Auth::guard('platform')->user() instanceof PlatformUser) {
            return $target;
        }

        return url('/__platform/sso/issue').'?'.http_build_query(
            ['return_to' => $target],
            '',
            '&',
            PHP_QUERY_RFC3986,
        );
    }

    private function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $origin = $scheme.'://'.strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($port !== null && ! (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
            $origin .= ':'.$port;
        }

        return $origin;
    }
}
