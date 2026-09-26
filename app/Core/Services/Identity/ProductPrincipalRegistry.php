<?php

namespace App\Core\Services\Identity;

use App\Core\Contracts\ProductPrincipalAdapter;
use App\Core\Models\PlatformUser;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;

final class ProductPrincipalRegistry
{
    /** @var array<string, ProductPrincipalAdapter> */
    private array $adapters = [];

    public function register(string $product, ProductPrincipalAdapter $adapter): void
    {
        if (isset($this->adapters[$product])) {
            throw new InvalidArgumentException("A principal adapter is already registered for [{$product}].");
        }

        $this->adapters[$product] = $adapter;
    }

    public function resolve(string $product, PlatformUser $user): ?Authenticatable
    {
        return ($this->adapters[$product] ?? null)?->resolve($user);
    }
}
