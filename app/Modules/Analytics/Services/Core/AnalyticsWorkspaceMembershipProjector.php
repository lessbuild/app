<?php

namespace App\Modules\Analytics\Services\Core;

use App\Core\Services\Identity\AbstractProductWorkspaceMembershipProjector;
use App\Modules\Analytics\Models\Workspace;

final class AnalyticsWorkspaceMembershipProjector extends AbstractProductWorkspaceMembershipProjector
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
        return 'users';
    }

    protected function localRole(string $role): string
    {
        return $role === 'admin' ? 'admin' : 'viewer';
    }

    protected function product(): string
    {
        return 'analytics';
    }
}
