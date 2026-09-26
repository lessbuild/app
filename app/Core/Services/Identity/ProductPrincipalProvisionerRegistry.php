<?php

namespace App\Core\Services\Identity;

use App\Core\Contracts\ProductPrincipalProvisioner;
use InvalidArgumentException;

final class ProductPrincipalProvisionerRegistry
{
    /** @var array<string, ProductPrincipalProvisioner> */
    private array $provisioners = [];

    public function register(string $product, ProductPrincipalProvisioner $provisioner): void
    {
        if (isset($this->provisioners[$product])) {
            throw new InvalidArgumentException("A principal provisioner is already registered for [{$product}].");
        }

        $this->provisioners[$product] = $provisioner;
    }

    public function get(string $product): ?ProductPrincipalProvisioner
    {
        return $this->provisioners[$product] ?? null;
    }
}
