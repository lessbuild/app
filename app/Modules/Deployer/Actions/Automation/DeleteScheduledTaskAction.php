<?php

namespace App\Modules\Deployer\Actions\Automation;

use App\Modules\Deployer\Models\ScheduledTask;

class DeleteScheduledTaskAction
{
    /**
     * Delete an already-authorized scheduled task and its cascading runs.
     */
    public function handle(ScheduledTask $task): void
    {
        $task->delete();
    }
}
