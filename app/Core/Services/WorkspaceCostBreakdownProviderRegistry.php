<?php

namespace App\Core\Services;

use App\Core\Contracts\WorkspaceCostBreakdownProvider;

final class WorkspaceCostBreakdownProviderRegistry
{
    /** @var array<string, WorkspaceCostBreakdownProvider> */
    private array $providers = [];

    public function register(string $product, WorkspaceCostBreakdownProvider $provider): void
    {
        $this->providers[$product] = $provider;
    }

    public function get(string $product): ?WorkspaceCostBreakdownProvider
    {
        return $this->providers[$product] ?? null;
    }
}
