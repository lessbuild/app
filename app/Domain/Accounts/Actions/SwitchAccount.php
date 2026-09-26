<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Gate;

final class SwitchAccount
{
    public function handle(User $user, Account $account): void
    {
        Gate::forUser($user)->authorize('view', $account);

        $user->forceFill(['current_account_id' => $account->id])->save();
    }
}
