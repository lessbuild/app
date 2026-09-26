<?php

namespace App\Core\Contracts;

use App\Core\Models\Workspace;

/** Creates or resolves a module-owned workspace projection for a canonical Core workspace. */
interface ProductWorkspaceProvisioner
{
    public function ensure(
        string $ownerProductPrincipalId,
        Workspace $coreWorkspace,
        ?string $mappedProductWorkspaceId = null,
    ): string;
}
