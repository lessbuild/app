<?php

namespace App\Core\Services;

use App\Core\Contracts\CustomerStatusPageProvider;
use App\Core\Data\Status\CustomerStatusPage;

final class CustomerStatusPageProviderRegistry
{
    /** @var array<string, CustomerStatusPageProvider> */
    private array $providers = [];

    public function register(string $product, CustomerStatusPageProvider $provider): void
    {
        $this->providers[$product] = $provider;
    }

    public function findPublished(string $product, string $slug): ?CustomerStatusPage
    {
        return ($this->providers[$product] ?? null)?->findPublished($slug);
    }

    public function subscribe(string $product, string $slug, string $email): bool
    {
        return ($this->providers[$product] ?? null)?->subscribe($slug, $email) ?? false;
    }
}
