<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Services\Identity\AbstractProductWorkspaceMembershipProjector;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Support\Facades\DB;

final class MonitorWorkspaceMembershipProjector extends AbstractProductWorkspaceMembershipProjector
{
    public function grant(string $productPrincipalId, string $coreWorkspaceId, string $role, array $previous = []): array
    {
        return DB::connection('monitor')->transaction(function () use ($productPrincipalId, $coreWorkspaceId, $role, $previous): array {
            abort_if(MonitorDeletionFence::canonicalWorkspaceIsFenced($coreWorkspaceId), 410, 'This Monitor workspace is being deleted.');
            MonitorDeletionFence::assertUserActive($productPrincipalId);
            foreach ($this->identities->sourceIdsForCanonical('monitor', 'workspace', $coreWorkspaceId, 'workspace') as $sourceWorkspaceId) {
                abort_if(MonitorDeletionFence::lockWorkspace($sourceWorkspaceId), 410, 'This Monitor workspace is being deleted.');
            }

            return parent::grant($productPrincipalId, $coreWorkspaceId, $role, $previous);
        }, attempts: 3);
    }

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
