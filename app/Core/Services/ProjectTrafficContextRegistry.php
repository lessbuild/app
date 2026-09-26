<?php

namespace App\Core\Services;

use App\Core\Contracts\ProjectTrafficContextProvider;

final class ProjectTrafficContextRegistry
{
    /** @var array<string, ProjectTrafficContextProvider> */
    private array $providers = [];

    public function register(string $product, ProjectTrafficContextProvider $provider): void
    {
        $this->providers[$product] = $provider;
    }

    public function get(string $product): ?ProjectTrafficContextProvider
    {
        return $this->providers[$product] ?? null;
    }
}
