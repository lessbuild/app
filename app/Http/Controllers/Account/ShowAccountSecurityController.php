<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Services\Identity\AccountSso;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ShowAccountSecurityController
{
    /**
     * Show the account's security rules and single sign-on settings.
     *
     * @param  Account  $account
     * @param  Request  $request
     * @param  AccountSso  $sso
     * @return View
     */
    public function __invoke(#[CurrentAccount] Account $account, Request $request, AccountSso $sso): View
    {
        return view('account.security', [
            'account' => $account,
            'ip' => (string) $request->ip(),
            'ssoVerified' => $sso->verified($request->session(), $account),
        ]);
    }
}
