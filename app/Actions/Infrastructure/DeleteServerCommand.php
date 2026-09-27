<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Models\Account;
use App\Models\ServerCommandExecution;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class DeleteServerCommand
{
    /** Delete a finished command and its output from the history. Queued or running ones stay. */
    public function handle(Account $account, User $actor, ServerCommandExecution $execution): bool
    {
        Gate::forUser($actor)->authorize('runCommands', $execution->server);

        return ServerCommandExecution::query()->whereKey($execution->id)->whereIn('status', ServerCommandExecution::FINISHED)->delete() === 1;
    }
}
