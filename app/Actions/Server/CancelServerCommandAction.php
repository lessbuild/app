<?php

namespace App\Actions\Server;

use App\Models\ServerCommandExecution;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class CancelServerCommandAction
{
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
