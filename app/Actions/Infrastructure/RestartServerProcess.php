<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Jobs\Infrastructure\SyncServerTask;
use App\Models\ServerProcess;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class RestartServerProcess
{
    /**
     * Restart every copy of a running process (queued), such as queue workers after a config change.
     *
     * @param  User  $actor
     * @param  ServerProcess  $process
     * @return void
     */
    public function handle(User $actor, ServerProcess $process): void
    {
        Gate::forUser($actor)->authorize('runCommands', $process->server);
        if ($process->status !== 'active') {
            throw ValidationException::withMessages(['process' => __('Only a running process can be restarted.')]);
        }
        SyncServerTask::dispatch(ServerProcess::class, $process->id, 'restart');
    }
}
