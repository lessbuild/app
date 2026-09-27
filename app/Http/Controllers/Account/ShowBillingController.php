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
use Illuminate\Support\Facades\Gate;

final class ShowBillingController
{
    public function __invoke(#[CurrentAccount] Account $account, Request $request, #[CurrentUser] User $user, BillingOverviewQuery $overview, InvoicesQuery $invoices): View
    {
        Gate::authorize('viewBilling', $account);

        return view('account.billing', [
            'account' => $account,
            'overview' => $overview->handle($account),
            'invoices' => $invoices->handle($account),
            'canManage' => $user->can('manageBilling', $account),
            'checkout' => $request->query('checkout'),
        ]);
    }
}
