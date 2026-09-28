<?php

declare(strict_types=1);

namespace App\Queries\Accounts;

use App\Models\User;

final class AccountSwitcherQuery
{
    /**
     * The accounts the person belongs to, by name, for the account switcher in the shell.
     *
     * @param  User  $user
     * @return list<array{id: string, name: string}>
     */
    public function handle(User $user): array
    {
        return array_values($user->accounts()->orderBy('name')->get(['accounts.id', 'accounts.name'])
            ->map(fn ($account): array => ['id' => (string) $account->id, 'name' => (string) $account->name])
            ->all());
    }
}
