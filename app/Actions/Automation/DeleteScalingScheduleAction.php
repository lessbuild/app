<?php

namespace App\Actions\Automation;

use App\Models\ScalingSchedule;

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
