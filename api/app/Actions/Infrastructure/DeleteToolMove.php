<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Models\Account;
use App\Models\Server;
use App\Models\ToolMove;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class DeleteToolMove
{
    /**
     * Forget a move: its API token and everything read from the other tool. Websites already moved stay.
     *
     * @param  User  $actor
     * @param  Account  $account
     * @param  ToolMove  $move
     * @return void
     */
    public function handle(User $actor, Account $account, ToolMove $move): void
    {
        Gate::forUser($actor)->authorize('create', [Server::class, $account]);
        abort_unless($move->account_id === $account->id, 404);
        $move->delete();
    }
}
