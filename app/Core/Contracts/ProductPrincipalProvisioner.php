<?php

namespace App\Core\Contracts;

use App\Core\Models\PlatformUser;

interface ProductPrincipalProvisioner
{
    /** Return false when the exact mapped local account no longer exists. */
    public function synchronizeMappedPrincipal(string $sourceId, PlatformUser $platformUser): bool;

    /** Create or synchronize a local principal and return its source ID. */
    public function provision(PlatformUser $platformUser): string;
}
