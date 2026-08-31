<?php

namespace App\Observers;

use App\Models\ServerCommandExecution;
use App\Services\ActivityRecorder;

class ServerCommandExecutionObserver
{
    public function __construct(private readonly ActivityRecorder $activity) {}

    public function created(ServerCommandExecution $execution): void
    {
        $this->record($execution, 'Server command was queued.');
    }

    public function updated(ServerCommandExecution $execution): void
    {
        if ($execution->wasChanged('status') && in_array($execution->status, [
            ServerCommandExecution::STATUS_SUCCEEDED,
            ServerCommandExecution::STATUS_FAILED,
            ServerCommandExecution::STATUS_CANCELED,
        ], true)) {
            $this->record($execution, "Server command {$execution->status}.");
        }
    }

    private function record(ServerCommandExecution $execution, string $message): void
    {
        $this->activity->record($execution, $execution->user_id, 'command', $message);
    }
}
