<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\ApiTokens\CreateApiToken;
use App\Http\Requests\Account\CreateApiTokenRequest;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

final class CreateApiTokenController
{
    public function __invoke(CreateApiTokenRequest $request, #[CurrentUser] User $user, CreateApiToken $create): RedirectResponse
    {
        $new = $create->handle($user, $this->account($user), $request->toData());

        // Flashed for exactly one page view; only the hash is kept.
        return to_route('account.api-tokens')->with('new_token', ['name' => $new->token->name, 'value' => $new->plainText]);
    }

    private function account(User $user): Account
    {
        return $user->currentAccount ?? abort(404);
    }
}
