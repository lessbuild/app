<?php

namespace App\Core\Services;

use App\Core\Contracts\ProjectProductSummaryProvider;

final class ProjectProductSummaryRegistry
{
    /** @var array<string, ProjectProductSummaryProvider> */
    private array $providers = [];

    public function register(string $product, ProjectProductSummaryProvider $provider): void
    {
        $this->providers[$product] = $provider;
    }

    public function get(string $product): ?ProjectProductSummaryProvider
    {
        return $this->providers[$product] ?? null;
    }
}
