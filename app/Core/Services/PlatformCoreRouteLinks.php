<?php

namespace App\Core\Services;

use App\Core\Models\PlatformUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/** Build exact-host links from product modules back to Core surfaces. */
final class PlatformCoreRouteLinks
{
    public function __construct(private readonly Request $request) {}

    /** @param array<string, mixed> $parameters */
    public function to(string $routeName, array $parameters = []): ?string
    {
        if (! Route::has($routeName)) {
            return null;
        }

        $target = route($routeName, $parameters);
        $targetOrigin = $this->origin($target);
        $dashboardOrigin = $this->origin((string) (config('platform.dashboard_url') ?: config('app.url')));
        $currentOrigin = $this->origin($this->request->getSchemeAndHttpHost());

        if ($targetOrigin === null || $dashboardOrigin === null || $targetOrigin !== $dashboardOrigin) {
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
