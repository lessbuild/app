<?php

namespace App\Core\Services\Auth;

use App\Core\Models\PlatformUser;
use App\Core\Services\Identity\ProductPrincipalRegistry;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Centralizes the per-product transition between legacy and Core authentication. */
final class ProductAuthentication
{
    private const PRODUCTS = ['deployer', 'monitor', 'analytics'];

    public function __construct(private readonly ProductPrincipalRegistry $principals) {}

    public function usesCoreAuthority(string $product): bool
    {
        $this->assertProduct($product);

        $authority = config("platform.products.{$product}.auth_authority", 'legacy');

        if (! in_array($authority, ['legacy', 'core'], true)) {
            throw new InvalidArgumentException("Unknown authentication authority [{$authority}] for product [{$product}].");
        }

        return $authority === 'core';
    }

    /** @return list<string> */
    public function authenticatedMiddleware(string $product): array
    {
        $this->assertProduct($product);

        return $this->usesCoreAuthority($product)
            ? ['auth:platform', 'platform.principal:'.$product]
            : ['auth'];
    }

    /** @return list<string> */
    public function guestMiddleware(string $product): array
    {
        $this->assertProduct($product);

        return $this->usesCoreAuthority($product)
            ? ['platform.product-guest:'.$product]
            : ['guest'];
    }

    public function resolvePrincipal(string $product, PlatformUser $user): ?Authenticatable
    {
        $this->assertProduct($product);

        return $this->principals->resolve($product, $user);
    }

    public function platformLoginUrl(Request $request, string $product): string
    {
        $this->assertProduct($product);

        $baseUrl = config("platform.products.{$product}.url");
        $returnTo = is_string($baseUrl) && filled($baseUrl)
            ? rtrim($baseUrl, '/').$request->getRequestUri()
            : null;

        return route('platform.login', array_filter(['return_to' => $returnTo]));
    }

    public function productForRequest(Request $request): ?string
    {
        $routeName = (string) $request->route()?->getName();

        if (str_starts_with($routeName, 'platform.') || str_starts_with($routeName, 'core.')) {
            return null;
        }

        foreach (self::PRODUCTS as $product) {
            if ($product !== 'deployer' && str_starts_with($routeName, $product.'.')) {
                return $product;
            }

            $host = config("platform.products.{$product}.host");
            if (is_string($host) && strtolower($this->hostname($host)) === strtolower($request->getHost())) {
                return $product;
            }
        }

        return $routeName !== '' ? 'deployer' : null;
    }

    public function dashboardRoute(string $product): string
    {
        $this->assertProduct($product);

        return match ($product) {
            'deployer' => 'dashboard',
            'monitor' => 'monitor.dashboard',
            'analytics' => 'analytics.dashboard',
        };
    }

    private function assertProduct(string $product): void
    {
        if (! in_array($product, self::PRODUCTS, true)) {
            throw new InvalidArgumentException("Unknown product authentication authority [{$product}].");
        }
    }

    private function hostname(string $host): string
    {
        $parts = parse_url(str_contains($host, '://') ? $host : 'https://'.$host);

        return is_array($parts) ? (string) ($parts['host'] ?? '') : '';
    }
}
