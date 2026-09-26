<?php

namespace App\Core\Services\Workspaces;

use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceInvitation;
use App\Core\Models\WorkspaceMembership;
use Illuminate\Support\Facades\DB;

final class RevokeWorkspaceInvitation
{
    public function handle(PlatformUser $actor, Workspace $workspace, WorkspaceInvitation $invitation): void
    {
        DB::connection('core')->transaction(function () use ($actor, $workspace, $invitation): void {
            $lockedWorkspace = Workspace::query()->whereKey($workspace->getKey())->lockForUpdate()->firstOrFail();
            abort_unless($invitation->workspace_id === $lockedWorkspace->getKey(), 404);

            $membership = WorkspaceMembership::query()
                ->where('workspace_id', $lockedWorkspace->getKey())
                ->where('user_id', $actor->getKey())
                ->currentlyActive()
                ->lockForUpdate()
                ->first();
            abort_unless($membership !== null && in_array($membership->role, ['owner', 'admin'], true), 403);

            $lockedInvitation = WorkspaceInvitation::query()
                ->whereKey($invitation->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($lockedInvitation->status === 'pending', 404);

            $lockedInvitation->forceFill(['status' => 'revoked'])->save();
        }, attempts: 3);
    }
}
