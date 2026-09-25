<?php

namespace App\Core\Services;

use App\Core\Contracts\WorkspaceMonitorStatusManagementProvider;

final class WorkspaceMonitorStatusManagementProviderRegistry
{
    /** @var array<string, WorkspaceMonitorStatusManagementProvider> */
    private array $providers = [];

    public function register(string $product, WorkspaceMonitorStatusManagementProvider $provider): void
    {
        $this->providers[$product] = $provider;
    }

    public function get(string $product): ?WorkspaceMonitorStatusManagementProvider
    {
        return $this->providers[$product] ?? null;
    }
}
