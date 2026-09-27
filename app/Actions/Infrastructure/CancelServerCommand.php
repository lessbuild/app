<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Models\Account;
use App\Models\ServerCommandExecution;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

final class CancelServerCommand
{
    /** Cancel a command that hasn't started. Returns false once it's running or finished. */
    public function handle(Account $account, User $actor, ServerCommandExecution $execution): bool
    {
        Gate::forUser($actor)->authorize('runCommands', $execution->server);

        return ServerCommandExecution::query()->whereKey($execution->id)->where('status', 'queued')
            ->update(['status' => 'canceled', 'finished_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u')]) === 1;
    }
}
