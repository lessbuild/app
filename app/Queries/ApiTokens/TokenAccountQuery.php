<?php

declare(strict_types=1);

namespace App\Queries\ApiTokens;

use App\Enums\AccountPermission;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\User;

final class TokenAccountQuery
{
    /**
     * The account a token acts in, or null once its creator has left the account or lost the
     * right to use API tokens there. Checked on every request, so removing someone disables their tokens.
     */
    public function handle(User $user, ApiToken $token): ?Account
    {
        $account = $token->account;

        return $token->tokenable_id === $user->id && ($account->roleOf($user)?->allows(AccountPermission::ManageApiTokens) ?? false)
            ? $account
            : null;
    }
}
