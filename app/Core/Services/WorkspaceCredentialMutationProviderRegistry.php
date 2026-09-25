<?php

namespace App\Core\Services;

use App\Core\Contracts\WorkspaceCredentialMutationProvider;

final class WorkspaceCredentialMutationProviderRegistry
{
    /** @var array<string, WorkspaceCredentialMutationProvider> */
    private array $providers = [];

    public function register(string $product, WorkspaceCredentialMutationProvider $provider): void
    {
        $this->providers[$product] = $provider;
    }

    public function get(string $product): ?WorkspaceCredentialMutationProvider
    {
        return $this->providers[$product] ?? null;
    }
}
