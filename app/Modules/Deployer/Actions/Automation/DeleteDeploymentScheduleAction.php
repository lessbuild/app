<?php

namespace App\Modules\Deployer\Actions\Automation;

use App\Modules\Deployer\Models\DeploymentSchedule;

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
