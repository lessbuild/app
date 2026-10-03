<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Accounts\RemoveMember;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class RemoveMemberController
{
    /**
     * Remove a member, or lets someone leave. People who left are taken to their dashboard, since the account's pages
     * are closed to them now.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  string  $membership
     * @param  RemoveMember  $remove
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, string $membership, RemoveMember $remove): JsonResponse
    {
        $target = $account->memberships()->with('user')->findOrFail($membership);
        $remove->handle($user, $target);

        return $target->user_id === $user->id
            ? response()->json(['redirect' => route('dashboard', [], false), 'message' => __('You left :account.', ['account' => $account->name])])
            : response()->json(['redirect' => route('account.members', [], false), 'message' => __(':name was removed.', ['name' => $target->user->name])]);
    }
}
