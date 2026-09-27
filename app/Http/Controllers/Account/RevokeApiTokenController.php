<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\ApiTokens\RevokeApiToken;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class RevokeApiTokenController
{
    public function __invoke(#[CurrentUser] User $user, int $token, RevokeApiToken $revoke): RedirectResponse
    {
        $revoke->handle($user, ApiToken::query()->where('account_id', $this->account($user)->id)->findOrFail($token));

        return to_route('account.api-tokens')->with('status', __('Token revoked. Requests using it now fail.'));
    }

    private function account(User $user): Account
    {
        return $user->currentAccount ?? abort(404);
    }
}
