<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/account/settings`. */
final class ShowAccountSettingsController
{
    /**
     * Return the account's name and whether the viewer may delete it.
     *
     * @param  Account  $account
     * @param  User  $user
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user): JsonResponse
    {
        return response()->json([
            'account' => ['id' => $account->id, 'name' => $account->name, 'createdAt' => $account->created_at?->toIso8601String()],
            'canDelete' => $user->can('delete', $account),
        ]);
    }
}
