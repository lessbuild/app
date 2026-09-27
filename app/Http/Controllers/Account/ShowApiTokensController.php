<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Enums\ApiScope;
use App\Http\Attributes\CurrentAccount;
use App\Http\Requests\Account\CreateApiTokenRequest;
use App\Models\Account;
use App\Models\User;
use App\Queries\ApiTokens\AccountApiTokensQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowApiTokensController
{
    /**
     * The API tokens page: the account's tokens and the form for new ones.
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, AccountApiTokensQuery $query): View
    {

        return view('account.api-tokens', [
            'account' => $account,
            'tokens' => $query->handle($account, $user),
            'scopes' => ApiScope::cases(),
            'expiryChoices' => CreateApiTokenRequest::EXPIRY_CHOICES,
        ]);
    }
}
