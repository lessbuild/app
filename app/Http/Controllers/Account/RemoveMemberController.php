<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Accounts\RemoveMember;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class RemoveMemberController
{
    /**
     * Removes a member, or lets someone leave. People who left are taken to their dashboard, since the account's pages
     * are closed to them now.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  string  $membership
     * @param  RemoveMember  $remove
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, string $membership, RemoveMember $remove): RedirectResponse
    {
        $target = $account->memberships()->with('user')->findOrFail($membership);
        $remove->handle($user, $target);

        return $target->user_id === $user->id
            ? to_route('dashboard')->with('status', __('You left :account.', ['account' => $account->name]))
            : to_route('account.members')->with('status', __(':name was removed.', ['name' => $target->user->name]));
    }
}
