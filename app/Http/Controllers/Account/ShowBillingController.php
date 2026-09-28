<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use App\Queries\Billing\BillingOverviewQuery;
use App\Queries\Billing\InvoicesQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ShowBillingController
{
    /**
     * The billing page: plans, usage, invoices, and a message after returning from checkout.
     *
     * @param  Account  $account
     * @param  Request  $request
     * @param  User  $user
     * @param  BillingOverviewQuery  $overview
     * @param  InvoicesQuery  $invoices
     * @return View
     */
    public function __invoke(#[CurrentAccount] Account $account, Request $request, #[CurrentUser] User $user, BillingOverviewQuery $overview, InvoicesQuery $invoices): View
    {

        return view('account.billing', [
            'account' => $account,
            'overview' => $overview->handle($account),
            'invoices' => $invoices->handle($account),
            'canManage' => $user->can('manageBilling', $account),
            'checkout' => $request->query('checkout'),
        ]);
    }
}
