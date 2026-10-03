<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Accounts\UpdateAccountSecurity;
use App\Http\Attributes\CurrentAccount;
use App\Http\Requests\Account\UpdateAccountSecurityRequest;
use App\Models\Account;
use App\Models\User;
use App\Services\Identity\AccountSso;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class UpdateAccountSecurityController
{
    /**
     * Save the account's security rules and single sign-on settings.
     *
     * @param  UpdateAccountSecurityRequest  $request
     * @param  User  $user
     * @param  Account  $account
     * @param  UpdateAccountSecurity  $update
     * @param  AccountSso  $sso
     * @return RedirectResponse
     */
    public function __invoke(UpdateAccountSecurityRequest $request, #[CurrentUser] User $user, #[CurrentAccount] Account $account, UpdateAccountSecurity $update, AccountSso $sso): RedirectResponse
    {
        $update->handle($user, $account, $request->toData(), (string) $request->ip(), $sso->verified($request->session(), $account));

        return to_route('account.security')->with('status', __('Security settings saved.'));
    }
}
