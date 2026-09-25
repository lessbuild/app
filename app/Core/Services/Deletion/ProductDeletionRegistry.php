<?php

namespace App\Core\Services\Deletion;

use App\Core\Contracts\ProductDeletionProvider;
use LogicException;

final class ProductDeletionRegistry
{
    /** @var array<string, ProductDeletionProvider> */
    private array $providers = [];

    public function register(ProductDeletionProvider $provider): void
    {
        if (isset($this->providers[$provider->product()])) {
            throw new LogicException('A deletion provider is already registered for this product.');
        }
        $this->providers[$provider->product()] = $provider;
    }

    public function get(string $product): ?ProductDeletionProvider
    {
        return $this->providers[$product] ?? null;
    }
}
