<?php

namespace App\Core\Services;

use App\Core\Contracts\ProductApiDocumentationProvider;
use App\Core\Data\Help\ProductApiReference;

final class ProductApiDocumentationRegistry
{
    /** @var array<string, ProductApiDocumentationProvider> */
    private array $providers = [];

    public function register(string $product, ProductApiDocumentationProvider $provider): void
    {
        $this->providers[$product] = $provider;
    }

    public function reference(string $product): ?ProductApiReference
    {
        return ($this->providers[$product] ?? null)?->reference();
    }
}
