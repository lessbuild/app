<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Events\AccountRenamed;
use App\Domain\Accounts\Models\Account;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Gate;

final class RenameAccount
{
    public function handle(User $actor, Account $account, string $name): Account
    {
        Gate::forUser($actor)->authorize('update', $account);

        $from = $account->name;
        $name = trim($name);
        if ($name === $from) {
            return $account;
        }

        $account->forceFill(['name' => $name])->save();
        AccountRenamed::dispatch($account, $from, $actor);

        return $account;
    }
}
