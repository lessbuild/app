<?php

namespace App\Core\Services\Identity;

use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Services\LegacyIdentityResolver;
use App\Core\Services\WorkspaceProjectAccess;

/** Resolve product-local workspaces through explicit Core maps for use and billing authorization. */
final class ProductWorkspaceAccess
{
    public function __construct(
        private readonly LegacyIdentityResolver $identities,
        private readonly WorkspaceProjectAccess $access,
    ) {}

    public function allows(
        PlatformUser $user,
        string $product,
        string $sourceEntity,
        string|int $sourceWorkspaceId,
    ): bool {
        $workspaceId = $this->identities->canonicalIdForSource(
            $product,
            $sourceEntity,
            (string) $sourceWorkspaceId,
            'workspace',
        );

        if (! is_string($workspaceId) || $workspaceId === '') {
            return false;
        }

        $workspace = Workspace::query()->find($workspaceId);
        if (! $workspace instanceof Workspace) {
            return false;
        }

        $membership = $this->access->activeMembership($user, $workspace);

        return $membership !== null && $this->access->hasProductAccess($membership, $product);
    }

    public function canManageBilling(
        PlatformUser $user,
        string $product,
        string $sourceEntity,
        string|int $sourceWorkspaceId,
    ): bool {
        $workspaceId = $this->identities->canonicalIdForSource(
            $product,
            $sourceEntity,
            (string) $sourceWorkspaceId,
            'workspace',
        );

        if (! is_string($workspaceId) || $workspaceId === '') {
            return false;
        }

        $workspace = Workspace::query()->find($workspaceId);

        return $workspace instanceof Workspace && $this->access->canManageBilling($user, $workspace);
    }
}
