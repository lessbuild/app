<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Events\AccountDeleted;
use App\Domain\Accounts\Models\Account;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Gate;

final class DeleteAccount
{
    /** Delete an account and everything that belongs to it (memberships, invitations, tokens, audit log). */
    public function handle(User $actor, Account $account): void
    {
        Gate::forUser($actor)->authorize('delete', $account);

        $id = $account->id;
        $name = $account->name;
        $account->delete();

        AccountDeleted::dispatch($id, $name, $actor);
    }
}
