<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Deploy;

use App\Http\Attributes\TokenAccount;
use App\Models\Account;
use App\Models\User;
use App\Queries\Deploy\DeployApiQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/v1/me` (Deployer API v1): the token's person and account, with the account's Deploy plan. */
final class ShowMeController
{
    /**
     * Returns the token's person and account.
     *
     * @param  User  $user
     * @param  Account  $account
     * @param  DeployApiQuery  $query
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, #[TokenAccount] Account $account, DeployApiQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->account($user, $account)]);
    }
}
