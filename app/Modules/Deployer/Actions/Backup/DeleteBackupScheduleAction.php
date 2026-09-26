<?php

namespace App\Modules\Deployer\Actions\Backup;

use App\Modules\Deployer\Models\WebsiteBackupSchedule;

class DeleteBackupScheduleAction
{
    /**
     * Remove one already-authorized backup schedule.
     */
    public function handle(WebsiteBackupSchedule $schedule): void
    {
        $schedule->delete();
    }
}
