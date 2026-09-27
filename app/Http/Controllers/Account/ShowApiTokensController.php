<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Enums\ApiScope;
use App\Http\Requests\Account\CreateApiTokenRequest;
use App\Models\Account;
use App\Models\User;
use App\Queries\ApiTokens\AccountApiTokensQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

final class ShowApiTokensController
{
    public function __invoke(#[CurrentUser] User $user, AccountApiTokensQuery $query): View
    {
        $account = $this->account($user);
        Gate::authorize('manageApiTokens', $account);

        return view('account.api-tokens', [
            'account' => $account,
            'tokens' => $query->handle($account, $user),
            'scopes' => ApiScope::cases(),
            'expiryChoices' => CreateApiTokenRequest::EXPIRY_CHOICES,
        ]);
    }

    private function account(User $user): Account
    {
        return $user->currentAccount ?? abort(404);
    }
}
