<?php

namespace App\Actions\Automation;

use App\Models\ScheduledTask;

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
