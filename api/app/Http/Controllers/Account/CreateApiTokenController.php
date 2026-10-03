<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\ApiTokens\CreateApiToken;
use App\Http\Attributes\CurrentAccount;
use App\Http\Requests\Account\CreateApiTokenRequest;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

final class CreateApiTokenController
{
    /**
     * Create an API token and flashes its secret for exactly one page view; only the hash is kept.
     *
     * @param  Account  $account
     * @param  CreateApiTokenRequest  $request
     * @param  User  $user
     * @param  CreateApiToken  $create
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, CreateApiTokenRequest $request, #[CurrentUser] User $user, CreateApiToken $create): RedirectResponse
    {
        $new = $create->handle($user, $account, $request->toData());

        // Flashed for exactly one page view; only the hash is kept.
        return to_route('account.api-tokens')->with('new_token', ['name' => $new->token->name, 'value' => $new->plainText]);
    }
}
