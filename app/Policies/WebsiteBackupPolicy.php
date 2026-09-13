<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WebsiteBackup;

class WebsiteBackupPolicy
{
    /**
     * Allow a manager in the backup website's selected workspace to request a restore.
     */
    public function restore(User $user, WebsiteBackup $backup): bool
    {
        return $this->canManage($user, $backup);
    }

    /**
     * Allow a manager in the backup website's selected workspace to run an isolated recovery verification.
     */
    public function verify(User $user, WebsiteBackup $backup): bool
    {
        return $this->canManage($user, $backup);
    }

    private function canManage(User $user, WebsiteBackup $backup): bool
    {
        $website = $backup->website;

        return $website !== null
            && (int) $website->organization_id === (int) $user->current_organization_id
            && ($website->organization?->permits($user, 'manage') ?? false);
    }
}
