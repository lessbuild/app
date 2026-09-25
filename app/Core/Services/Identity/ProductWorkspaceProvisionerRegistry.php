<?php

namespace App\Core\Services\Identity;

use App\Core\Contracts\ProductWorkspaceProvisioner;
use InvalidArgumentException;

final class ProductWorkspaceProvisionerRegistry
{
    /** @var array<string, ProductWorkspaceProvisioner> */
    private array $provisioners = [];

    public function register(string $product, ProductWorkspaceProvisioner $provisioner): void
    {
        if (isset($this->provisioners[$product])) {
            throw new InvalidArgumentException("A workspace provisioner is already registered for [{$product}].");
        }

        $this->provisioners[$product] = $provisioner;
    }

    public function get(string $product): ?ProductWorkspaceProvisioner
    {
        return $this->provisioners[$product] ?? null;
    }
}
