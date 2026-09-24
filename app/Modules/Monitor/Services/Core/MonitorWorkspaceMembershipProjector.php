<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Services\Identity\AbstractProductWorkspaceMembershipProjector;
use App\Modules\Monitor\Models\Workspace;

final class MonitorWorkspaceMembershipProjector extends AbstractProductWorkspaceMembershipProjector
{
    protected function workspaceModel(): string
    {
        return Workspace::class;
    }

    protected function workspaceEntity(): string
    {
        return 'workspace';
    }

    protected function membershipRelation(): string
    {
        return 'members';
    }

    protected function localRole(string $role): string
    {
        return in_array($role, ['admin', 'member', 'viewer'], true) ? $role : 'viewer';
    }

    protected function product(): string
    {
        return 'monitor';
    }
}
