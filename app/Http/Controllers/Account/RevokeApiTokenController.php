<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\ApiTokens\RevokeApiToken;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class RevokeApiTokenController
{
    /**
     * Revoke one of the account's API tokens; requests using it fail from now on.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  int  $token
     * @param  RevokeApiToken  $revoke
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, int $token, RevokeApiToken $revoke): RedirectResponse
    {
        $revoke->handle($user, ApiToken::query()->where('account_id', $account->id)->findOrFail($token));

        return to_route('account.api-tokens')->with('status', __('Token revoked. Requests using it now fail.'));
    }
}
