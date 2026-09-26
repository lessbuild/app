<?php

namespace App\Core\Services\Restoration;

use App\Core\Contracts\ProductResourceRestorationProvider;
use LogicException;

final class ProductResourceRestorationRegistry
{
    /** @var array<string, ProductResourceRestorationProvider> */
    private array $providers = [];

    public function register(ProductResourceRestorationProvider $provider): void
    {
        if ($provider->product() === '' || $provider->resourceTypes() === []) {
            throw new LogicException('Restoration providers must declare a product and resource types.');
        }

        foreach ($provider->resourceTypes() as $type) {
            $key = $provider->product().':'.$type;
            if ($type === '' || (isset($this->providers[$key]) && get_class($this->providers[$key]) !== get_class($provider))) {
                throw new LogicException('A restoration provider is invalid or already registered.');
            }
            $this->providers[$key] = $provider;
        }
    }

    public function get(string $product, string $resourceType): ?ProductResourceRestorationProvider
    {
        return $this->providers[$product.':'.$resourceType] ?? null;
    }
}
