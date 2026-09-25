<?php

namespace App\Core\Services\Billing;

use App\Core\Enums\ProductKey;
use App\Core\Models\PlatformUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/** Build allowlisted links from Core into each product's existing billing screen. */
final class PlatformProductBillingLinks
{
    /** @var array<string, string> */
    private const ROUTES = [
        'deployer' => 'billing.index',
        'monitor' => 'monitor.settings.billing',
        'analytics' => 'analytics.workspaces.billing',
    ];

    public function __construct(private readonly Request $request) {}

    public function supports(string $product): bool
    {
        return ProductKey::tryFrom($product) !== null
            && isset(self::ROUTES[$product])
            && Route::has(self::ROUTES[$product]);
    }

    public function for(string $product, string|int|null $productWorkspaceId = null): ?string
    {
        if (! $this->supports($product)
            || ! is_string($productWorkspaceId) && ! is_int($productWorkspaceId)
            || ! ctype_digit((string) $productWorkspaceId)) {
            return null;
        }

        $route = self::ROUTES[$product];
        $workspaceParameter = match ($product) {
            'deployer' => 'organization_id',
            'analytics' => 'workspace',
            default => 'workspace_id',
        };
        $target = route($route, [$workspaceParameter => (string) $productWorkspaceId]);

        $targetOrigin = $this->origin($target);
        $currentOrigin = strtolower($this->request->getSchemeAndHttpHost());

        if ($targetOrigin === null) {
            return null;
        }

        $productUrl = config("platform.products.{$product}.url");
        if (is_string($productUrl) && filled($productUrl) && $this->origin($productUrl) !== $targetOrigin) {
            return null;
        }

        if ($targetOrigin === $currentOrigin) {
            return $target;
        }

        if (! (Auth::guard('platform')->user() instanceof PlatformUser)) {
            return null;
        }

        return url('/__platform/sso/issue').'?'.http_build_query(['return_to' => $target], '', '&', PHP_QUERY_RFC3986);
    }

    private function origin(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = strtolower($parts['scheme'].'://'.$parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($port !== null && ! (($parts['scheme'] === 'https' && $port === 443) || ($parts['scheme'] === 'http' && $port === 80))) {
            $origin .= ':'.$port;
        }

        return $origin;
    }
}
