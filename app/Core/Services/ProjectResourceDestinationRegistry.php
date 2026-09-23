<?php

namespace App\Core\Services;

use App\Core\Contracts\ProjectResourceDestinationProvider;

final class ProjectResourceDestinationRegistry
{
    /** @var array<string, ProjectResourceDestinationProvider> */
    private array $providers = [];

    public function register(string $product, ProjectResourceDestinationProvider $provider): void
    {
        $this->providers[$product] = $provider;
    }

    public function get(string $product): ?ProjectResourceDestinationProvider
    {
        return $this->providers[$product] ?? null;
    }
}
