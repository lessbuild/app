<?php

namespace App\Core\Services;

use App\Core\Contracts\WorkspaceCustomerStatusManagementProvider;

final class WorkspaceCustomerStatusManagementProviderRegistry
{
    /** @var array<string, WorkspaceCustomerStatusManagementProvider> */
    private array $providers = [];

    public function register(string $product, WorkspaceCustomerStatusManagementProvider $provider): void
    {
        $this->providers[$product] = $provider;
    }

    public function get(string $product): ?WorkspaceCustomerStatusManagementProvider
    {
        return $this->providers[$product] ?? null;
    }
}
