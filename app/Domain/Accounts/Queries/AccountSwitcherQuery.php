<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Queries;

use App\Domain\Identity\Models\User;

final class AccountSwitcherQuery
{
    /** @return list<array{id: string, name: string}> */
    public function handle(User $user): array
    {
        return array_values($user->accounts()->orderBy('name')->get(['accounts.id', 'accounts.name'])
            ->map(fn ($account): array => ['id' => (string) $account->id, 'name' => (string) $account->name])
            ->all());
    }
}
