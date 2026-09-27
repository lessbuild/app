<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class SwitchAccount
{
    public function handle(User $user, Account $account): void
    {
        Gate::forUser($user)->authorize('view', $account);

        $user->forceFill(['current_account_id' => $account->id])->save();
    }
}
