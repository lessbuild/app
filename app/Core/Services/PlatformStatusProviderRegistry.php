<?php

namespace App\Core\Services;

use App\Core\Contracts\PlatformStatusProvider;

final class PlatformStatusProviderRegistry
{
    /** @var array<string, PlatformStatusProvider> */
    private array $providers = [];

    public function register(string $product, PlatformStatusProvider $provider): void
    {
        $this->providers[$product] = $provider;
    }

    public function get(string $product): ?PlatformStatusProvider
    {
        return $this->providers[$product] ?? null;
    }
}
