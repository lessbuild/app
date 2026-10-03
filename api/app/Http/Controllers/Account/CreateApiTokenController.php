<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\ApiTokens\CreateApiToken;
use App\Http\Attributes\CurrentAccount;
use App\Http\Requests\Account\CreateApiTokenRequest;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `POST /api/app/account/api-tokens`. */
final class CreateApiTokenController
{
    /**
     * Create a token and return its value this once; only its hash is kept, so it can't be shown again.
     *
     * @param  Account  $account
     * @param  CreateApiTokenRequest  $request
     * @param  User  $user
     * @param  CreateApiToken  $create
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, CreateApiTokenRequest $request, #[CurrentUser] User $user, CreateApiToken $create): JsonResponse
    {
        $new = $create->handle($user, $account, $request->toData());

        return response()->json(['token' => ['id' => $new->token->id, 'name' => $new->token->name, 'value' => $new->plainText]], 201);
    }
}
