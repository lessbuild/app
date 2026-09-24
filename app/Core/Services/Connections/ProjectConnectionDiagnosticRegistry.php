<?php

namespace App\Core\Services\Connections;

use App\Core\Contracts\ProjectConnectionDiagnosticProvider;

final class ProjectConnectionDiagnosticRegistry
{
    /** @var array<string, ProjectConnectionDiagnosticProvider> */
    private array $providers = [];

    public function register(string $product, ProjectConnectionDiagnosticProvider $provider): void
    {
        $this->providers[$product] = $provider;
    }

    public function get(string $product): ?ProjectConnectionDiagnosticProvider
    {
        return $this->providers[$product] ?? null;
    }
}
