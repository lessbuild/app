<?php

namespace App\Core\Services\Blueprints;

use App\Core\Contracts\ProjectBlueprintProvider;

final class ProjectBlueprintProviderRegistry
{
    /** @var array<string, ProjectBlueprintProvider> */
    private array $providers = [];

    public function register(string $product, ProjectBlueprintProvider $provider): void
    {
        $this->providers[$product] = $provider;
    }

    public function get(string $product): ?ProjectBlueprintProvider
    {
        return $this->providers[$product] ?? null;
    }

    /** @return array<string, ProjectBlueprintProvider> */
    public function all(): array
    {
        return $this->providers;
    }
}
