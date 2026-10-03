<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Jobs\Infrastructure\ExecuteServerCommand;
use App\Models\Account;
use App\Models\Server;
use App\Models\ServerCommandExecution;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class RunServerCommand
{
    /**
     * Queue a root shell command on an active server, one at a time per server. With `$rerunOf`, the earlier command
     * (which must have finished) is run again.
     *
     * @param  Account  $account
     * @param  User  $actor
     * @param  Server  $server
     * @param  string|null  $command
     * @param  ServerCommandExecution|null  $rerunOf
     * @return ServerCommandExecution
     */
    public function handle(Account $account, User $actor, Server $server, ?string $command, ?ServerCommandExecution $rerunOf = null): ServerCommandExecution
    {
        Gate::forUser($actor)->authorize('runCommands', $server);

        return DB::transaction(function () use ($account, $actor, $server, $command, $rerunOf): ServerCommandExecution {
            $locked = Server::query()->where('account_id', $account->id)->lockForUpdate()->findOrFail($server->id);
            if ($locked->provisioning_status !== Server::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['command' => __('Commands can run once provisioning finishes.')]);
            }
            if ($locked->commandExecutions()->whereIn('status', ServerCommandExecution::ACTIVE)->exists()) {
                throw ValidationException::withMessages(['command' => __('Wait for the current command to finish first.')]);
            }
            if ($rerunOf !== null) {
                $rerunOf = $locked->commandExecutions()->findOrFail($rerunOf->id);
                if (! $rerunOf->isFinished()) {
                    throw ValidationException::withMessages(['command' => __('Only finished commands can run again.')]);
                }
                $command = $rerunOf->command;
            }
            $execution = new ServerCommandExecution;
            $execution->forceFill(['server_id' => $locked->id, 'user_id' => $actor->id, 'command' => (string) $command, 'status' => 'queued', 'rerun_from_execution_id' => $rerunOf?->id])->save();
            ExecuteServerCommand::dispatch($execution->id)->afterCommit();

            return $execution;
        });
    }
}
