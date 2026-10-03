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
use Illuminate\Http\JsonResponse;

/** `GET /api/app/account/api-tokens`. */
final class ShowApiTokensController
{
    /**
     * Return the account's API tokens (never their values), the scopes and lifetimes a new token can have, and where
     * the command-line tool installs from.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  AccountApiTokensQuery  $query
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, AccountApiTokensQuery $query): JsonResponse
    {
        return response()->json([
            'account' => ['id' => $account->id, 'name' => $account->name],
            'tokens' => $query->handle($account, $user),
            'scopes' => array_map(fn (ApiScope $scope): array => ['value' => $scope->value, 'label' => $scope->label(), 'group' => $scope->group()], ApiScope::cases()),
            'expiryChoices' => CreateApiTokenRequest::EXPIRY_CHOICES,
            'cliInstallUrl' => route('cli.install'),
        ]);
    }
}
