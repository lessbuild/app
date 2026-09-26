<?php

namespace App\Core\Services\Identity;

use App\Core\Contracts\ProductWorkspaceMembershipProjector;
use InvalidArgumentException;

final class ProductWorkspaceMembershipProjectorRegistry
{
    /** @var array<string, ProductWorkspaceMembershipProjector> */
    private array $projectors = [];

    public function register(string $product, ProductWorkspaceMembershipProjector $projector): void
    {
        if (isset($this->projectors[$product])) {
            throw new InvalidArgumentException("A workspace membership projector is already registered for [{$product}].");
        }

        $this->projectors[$product] = $projector;
    }

    public function get(string $product): ?ProductWorkspaceMembershipProjector
    {
        return $this->projectors[$product] ?? null;
    }
}
