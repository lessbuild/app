<?php

namespace App\Core\Services;

use App\Core\Contracts\ProjectSetupProvider;

final class ProjectSetupRegistry
{
    /** @var array<string, ProjectSetupProvider> */
    private array $providers = [];

    public function register(string $product, ProjectSetupProvider $provider): void
    {
        $this->providers[$product] = $provider;
    }

    public function get(string $product): ?ProjectSetupProvider
    {
        return $this->providers[$product] ?? null;
    }
}
