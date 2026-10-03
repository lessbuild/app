<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class EditAccountSettingsController
{
    /**
     * Show the account settings page, with the danger zone only for people who may delete the account.
     *
     * @param  Account  $account
     * @param  User  $user
     * @return View
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user): View
    {

        return view('account.settings', ['account' => $account, 'canDelete' => $user->can('delete', $account)]);
    }
}
