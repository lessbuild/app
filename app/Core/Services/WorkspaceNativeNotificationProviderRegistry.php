<?php

namespace App\Core\Services;

use App\Core\Contracts\WorkspaceNativeNotificationProvider;

final class WorkspaceNativeNotificationProviderRegistry
{
    /** @var array<string, WorkspaceNativeNotificationProvider> */
    private array $providers = [];

    public function register(string $product, WorkspaceNativeNotificationProvider $provider): void
    {
        $this->providers[$product] = $provider;
    }

    public function get(string $product): ?WorkspaceNativeNotificationProvider
    {
        return $this->providers[$product] ?? null;
    }

    /** @return array<string, WorkspaceNativeNotificationProvider> */
    public function all(): array
    {
        return $this->providers;
    }
}
