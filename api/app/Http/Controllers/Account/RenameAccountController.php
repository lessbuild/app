<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Accounts\RenameAccount;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RenameAccountController
{
    /**
     * Rename the account.
     *
     * @param  Account  $account
     * @param  Request  $request
     * @param  User  $user
     * @param  RenameAccount  $rename
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, Request $request, #[CurrentUser] User $user, RenameAccount $rename): JsonResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $rename->handle($user, $account, $validated['name']);

        return response()->json(['redirect' => route('account.settings', [], false), 'message' => __('Account renamed.')]);
    }
}
