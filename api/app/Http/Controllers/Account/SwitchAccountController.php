<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Accounts\SwitchAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class SwitchAccountController
{
    /**
     * Switch the person's current account. Accounts they don't belong to look like they don't exist.
     *
     * @param  User  $user
     * @param  Account  $account
     * @param  SwitchAccount  $switch
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Account $account, SwitchAccount $switch): RedirectResponse
    {
        abort_unless($user->can('view', $account), 404);
        $switch->handle($user, $account);

        return to_route('dashboard');
    }
}
