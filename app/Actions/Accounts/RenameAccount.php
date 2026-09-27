<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Events\Accounts\AccountRenamed;
use App\Models\Account;
use App\Models\User;
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
