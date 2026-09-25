<?php

namespace App\Core\Services;

use App\Core\Contracts\WorkspaceProductUsageProvider;

final class WorkspaceProductUsageProviderRegistry
{
    /** @var array<string, WorkspaceProductUsageProvider> */
    private array $providers = [];

    public function register(string $product, WorkspaceProductUsageProvider $provider): void
    {
        $this->providers[$product] = $provider;
    }

    public function get(string $product): ?WorkspaceProductUsageProvider
    {
        return $this->providers[$product] ?? null;
    }
}
