<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Accounts\Models\Account;
use App\Domain\Api\Actions\CreateApiToken;
use App\Domain\Api\Actions\RevokeApiToken;
use App\Domain\Api\Enums\ApiScope;
use App\Domain\Api\Models\ApiToken;
use App\Domain\Api\Queries\AccountApiTokensQuery;
use App\Domain\Identity\Models\User;
use App\Http\Requests\Account\CreateApiTokenRequest;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

final class ApiTokensController
{
    public function index(#[CurrentUser] User $user, AccountApiTokensQuery $query): View
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

    public function store(CreateApiTokenRequest $request, #[CurrentUser] User $user, CreateApiToken $create): RedirectResponse
    {
        $new = $create->handle($user, $this->account($user), $request->toData());

        // Flashed for exactly one page view; only the hash is kept.
        return to_route('account.api-tokens')->with('new_token', ['name' => $new->token->name, 'value' => $new->plainText]);
    }

    public function destroy(#[CurrentUser] User $user, int $token, RevokeApiToken $revoke): RedirectResponse
    {
        $revoke->handle($user, ApiToken::query()->where('account_id', $this->account($user)->id)->findOrFail($token));

        return to_route('account.api-tokens')->with('status', __('Token revoked. Requests using it now fail.'));
    }

    private function account(User $user): Account
    {
        return $user->currentAccount ?? abort(404);
    }
}
