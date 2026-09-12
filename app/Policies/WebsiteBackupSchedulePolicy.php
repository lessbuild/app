<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WebsiteBackupSchedule;

class WebsiteBackupSchedulePolicy
{
    /**
     * Allow a manager in the selected workspace to create a backup schedule.
     */
    public function create(User $user): bool
    {
        return $user->currentOrganization?->permits($user, 'manage') ?? false;
    }

    /**
     * Allow only a manager in the schedule website's selected workspace to remove it.
     */
    public function delete(User $user, WebsiteBackupSchedule $schedule): bool
    {
        $website = $schedule->website;

        return $website !== null
            && (int) $website->organization_id === (int) $user->current_organization_id
            && ($website->organization?->permits($user, 'manage') ?? false);
    }
}
