<?php

namespace App\Core\Services;

use App\Core\Contracts\ProjectInfrastructureProvider;

final class ProjectInfrastructureProviderRegistry
{
    /** @var array<string, ProjectInfrastructureProvider> */
    private array $providers = [];

    public function register(string $product, ProjectInfrastructureProvider $provider): void
    {
        $this->providers[$product] = $provider;
    }

    public function get(string $product): ?ProjectInfrastructureProvider
    {
        return $this->providers[$product] ?? null;
    }
}
