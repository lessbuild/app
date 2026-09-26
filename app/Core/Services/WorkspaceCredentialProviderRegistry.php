<?php

namespace App\Core\Services;

use App\Core\Contracts\WorkspaceCredentialProvider;

final class WorkspaceCredentialProviderRegistry
{
    /** @var array<string, WorkspaceCredentialProvider> */
    private array $providers = [];

    public function register(string $product, WorkspaceCredentialProvider $provider): void
    {
        $this->providers[$product] = $provider;
    }

    public function get(string $product): ?WorkspaceCredentialProvider
    {
        return $this->providers[$product] ?? null;
    }
}
