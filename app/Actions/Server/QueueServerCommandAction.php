<?php

namespace App\Actions\Server;

use App\Jobs\Server\RunServerCommandJob;
use App\Models\Server;
use App\Models\ServerCommandExecution;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QueueServerCommandAction
{
    public function handle(Server $server, User $user, string $command): ServerCommandExecution
    {
        return DB::transaction(function () use ($command, $server, $user): ServerCommandExecution {
            $locked = Server::query()->lockForUpdate()->findOrFail($server->id);
            if ((int) $locked->user_id !== (int) $user->id) {
                throw new AuthorizationException;
            }

            if ($locked->provisioning_status !== Server::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'command' => __('Commands are available after provisioning finishes.'),
                ]);
            }

            if ($locked->commandExecutions()->active()->exists()) {
                throw ValidationException::withMessages([
                    'command' => __('Wait for the current command to finish before starting another one.'),
                ]);
            }

            $execution = $locked->commandExecutions()->create([
                'user_id' => $user->id,
                'command' => $command,
                'status' => ServerCommandExecution::STATUS_QUEUED,
            ]);

            RunServerCommandJob::dispatch($execution->id)->afterCommit();

            return $execution;
        });
    }
}
