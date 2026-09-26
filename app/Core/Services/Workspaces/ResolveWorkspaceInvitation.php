<?php

namespace App\Core\Services\Workspaces;

use App\Core\Models\WorkspaceInvitation;

final class ResolveWorkspaceInvitation
{
    public function findValid(string $token, bool $lockForUpdate = false): ?WorkspaceInvitation
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            return null;
        }

        $query = WorkspaceInvitation::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->with(['workspace', 'invitedBy']);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }
}
