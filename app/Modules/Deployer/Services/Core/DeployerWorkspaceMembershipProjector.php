<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Services\Identity\AbstractProductWorkspaceMembershipProjector;
use App\Modules\Deployer\Models\Organization;

final class DeployerWorkspaceMembershipProjector extends AbstractProductWorkspaceMembershipProjector
{
    protected function workspaceModel(): string
    {
        return Organization::class;
    }

    protected function workspaceEntity(): string
    {
        return 'organization';
    }

    protected function membershipRelation(): string
    {
        return 'members';
    }

    protected function localRole(string $role): string
    {
        return match ($role) {
            'admin' => 'admin',
            'member' => 'developer',
            default => 'viewer',
        };
    }

    protected function product(): string
    {
        return 'deployer';
    }
}
