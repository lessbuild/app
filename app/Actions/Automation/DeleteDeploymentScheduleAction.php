<?php

namespace App\Actions\Automation;

use App\Models\DeploymentSchedule;

class DeleteDeploymentScheduleAction
{
    /**
     * Delete an already-authorized deployment schedule.
     */
    public function handle(DeploymentSchedule $schedule): void
    {
        $schedule->delete();
    }
}
