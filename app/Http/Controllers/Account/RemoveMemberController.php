<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Accounts\RemoveMember;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class RemoveMemberController
{
    public function __invoke(#[CurrentUser] User $user, string $membership, RemoveMember $remove): RedirectResponse
    {
        $account = $this->account($user);
        $target = $account->memberships()->with('user')->findOrFail($membership);
        $remove->handle($user, $target);

        return $target->user_id === $user->id
            ? to_route('dashboard')->with('status', __('You left :account.', ['account' => $account->name]))
            : to_route('account.members')->with('status', __(':name was removed.', ['name' => $target->user->name]));
    }

    private function account(User $user): Account
    {
        return $user->currentAccount ?? abort(404);
    }
}
