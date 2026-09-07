<?php

namespace App\Actions\Server;

use App\Models\ServerCommandExecution;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class CancelServerCommandAction
{
    /**
     * Lock a command execution, require its requesting owner, and cancel it only while it remains queued.
     *
     * @param  ServerCommandExecution  $execution  Server command execution whose owner and lifecycle state are checked.
     * @param  User  $user  User whose ownership authorizes the operation.
     * @return bool Whether the queued execution was canceled; false if its status already changed.
     *
     * @throws AuthorizationException If the user does not own the command execution.
     */
    public function handle(ServerCommandExecution $execution, User $user): bool
    {
        return DB::transaction(function () use ($execution, $user): bool {
            $locked = ServerCommandExecution::query()
                ->lockForUpdate()
                ->findOrFail($execution->id);

            if ((int) $locked->user_id !== (int) $user->id) {
                throw new AuthorizationException;
            }

            if ($locked->status !== ServerCommandExecution::STATUS_QUEUED) {
                return false;
            }

            $locked->update([
                'status' => ServerCommandExecution::STATUS_CANCELED,
                'finished_at' => now(),
            ]);

            return true;
        });
    }
}
