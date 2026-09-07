<?php

namespace App\Observers;

use App\Models\ServerCommandExecution;
use App\Services\ActivityRecorder;

class ServerCommandExecutionObserver
{
    /**
     * Use the activity recorder for command queue and completion audit events.
     *
     * @param  ActivityRecorder  $activity  Recorder for attributed lifecycle events in the application activity stream.
     */
    public function __construct(private readonly ActivityRecorder $activity) {}

    /**
     * Record the newly queued server command in its requester's activity history.
     *
     * @param  ServerCommandExecution  $execution  Server command execution whose owner and lifecycle state are checked.
     */
    public function created(ServerCommandExecution $execution): void
    {
        $this->record($execution, 'Server command was queued.');
    }

    /**
     * Record completion only when an execution changes into a terminal status.
     *
     * @param  ServerCommandExecution  $execution  Server command execution whose owner and lifecycle state are checked.
     */
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

    /**
     * Store command activity against the execution owner and related execution identifier.
     *
     * @param  ServerCommandExecution  $execution  Server command execution whose owner and lifecycle state are checked.
     * @param  string  $message  Human-readable lifecycle event to retain in the activity stream.
     */
    private function record(ServerCommandExecution $execution, string $message): void
    {
        $this->activity->record($execution, $execution->user_id, 'command', $message);
    }
}
