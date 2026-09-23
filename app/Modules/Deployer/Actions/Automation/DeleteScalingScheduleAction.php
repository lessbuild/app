<?php

namespace App\Modules\Deployer\Actions\Automation;

use App\Modules\Deployer\Models\ScalingSchedule;

class DeleteScalingScheduleAction
{
    /**
     * Delete an already-authorized scaling schedule.
     */
    public function handle(ScalingSchedule $schedule): void
    {
        $schedule->delete();
    }
}
