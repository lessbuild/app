<?php

namespace App\Core\Services;

use App\Core\Contracts\ProjectResourceLinkProvider;

final class ProjectResourceLinkRegistry
{
    /** @var array<string, ProjectResourceLinkProvider> */
    private array $providers = [];

    public function register(string $product, ProjectResourceLinkProvider $provider): void
    {
        $this->providers[$product] = $provider;
    }

    public function get(string $product): ?ProjectResourceLinkProvider
    {
        return $this->providers[$product] ?? null;
    }
}
