<?php

namespace App\Actions\Backup;

use App\Models\WebsiteBackupSchedule;

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
