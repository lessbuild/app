<?php

namespace App\Core\Services\Search;

use App\Core\Contracts\WorkspaceSearchProvider;

final class WorkspaceSearchProviderRegistry
{
    /** @var array<string, WorkspaceSearchProvider> */
    private array $providers = [];

    public function register(string $product, WorkspaceSearchProvider $provider): void
    {
        $this->providers[$product] = $provider;
    }

    public function get(string $product): ?WorkspaceSearchProvider
    {
        return $this->providers[$product] ?? null;
    }
}
