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
    /**
     * Lock an active server, enforce ownership and a single active command, and queue an encrypted execution after the transaction commits.
     *
     * @param  Server  $server  Managed server supplying its provisioning state and remote connection details.
     * @param  User  $user  Owner requesting the command execution.
     * @param  string  $command  Shell command to encrypt and queue unless a rerun source replaces it.
     * @param  int|null  $rerunFromExecutionId  Optional finished execution on this server whose stored command should be reused.
     * @return ServerCommandExecution The persisted queued execution, containing the original command when rerunning a finished execution.
     *
     * @throws AuthorizationException If the requesting user does not own the server.
     * @throws ValidationException If the server, active-command state, or rerun source is ineligible.
     */
    public function handle(
        Server $server,
        User $user,
        string $command,
        ?int $rerunFromExecutionId = null,
    ): ServerCommandExecution {
        return DB::transaction(function () use ($command, $rerunFromExecutionId, $server, $user): ServerCommandExecution {
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

            $rerunFrom = null;
            if ($rerunFromExecutionId !== null) {
                $rerunFrom = $locked->commandExecutions()
                    ->lockForUpdate()
                    ->findOrFail($rerunFromExecutionId);
                if ($rerunFrom->statusEnum()?->isTerminal() !== true) {
                    throw ValidationException::withMessages([
                        'command' => __('Only completed commands can be run again.'),
                    ]);
                }
                $command = $rerunFrom->command;
            }

            $execution = $locked->commandExecutions()->create([
                'user_id' => $user->id,
                'command' => $command,
                'status' => ServerCommandExecution::STATUS_QUEUED,
                'rerun_from_execution_id' => $rerunFrom?->id,
            ]);

            RunServerCommandJob::dispatch($execution->id)->afterCommit();

            return $execution;
        });
    }
}
