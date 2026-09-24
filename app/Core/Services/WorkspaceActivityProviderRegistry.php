<?php

namespace App\Core\Services;

use App\Core\Contracts\WorkspaceActivityProvider;

final class WorkspaceActivityProviderRegistry
{
    /** @var array<string, WorkspaceActivityProvider> */
    private array $providers = [];

    public function register(string $product, WorkspaceActivityProvider $provider): void
    {
        $this->providers[$product] = $provider;
    }

    public function get(string $product): ?WorkspaceActivityProvider
    {
        return $this->providers[$product] ?? null;
    }
}
