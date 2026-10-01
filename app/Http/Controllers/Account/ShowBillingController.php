<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\BillingAccount;
use App\Models\User;
use App\Queries\Billing\BillingOverviewQuery;
use App\Queries\Billing\CostViewQuery;
use App\Queries\Billing\InvoicesQuery;
use App\Services\Billing\Referrals;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ShowBillingController
{
    /**
     * Show the billing page: plans, usage, invoices, and a message after returning from checkout.
     *
     * @param  Account  $account
     * @param  Request  $request
     * @param  User  $user
     * @param  BillingOverviewQuery  $overview
     * @param  InvoicesQuery  $invoices
     * @param  CostViewQuery  $costs
     * @param  Referrals  $referrals
     * @return View
     */
    public function __invoke(#[CurrentAccount] Account $account, Request $request, #[CurrentUser] User $user, BillingOverviewQuery $overview, InvoicesQuery $invoices, CostViewQuery $costs, Referrals $referrals): View
    {
        $summary = $overview->handle($account);
        // One tab for the account's plan as a whole, one per service, and one for invoices and referrals.
        $tabs = ['overview' => __('Overview')];
        foreach ($summary->services as $service) {
            $tabs[$service->key] = $service->name;
        }
        $tabs['costs'] = __('Costs by project');
        $tabs['invoices'] = __('Invoices');
        $tab = is_string($request->query('tab')) && isset($tabs[$request->query('tab')]) ? $request->query('tab') : 'overview';

        return view('account.billing', [
            'account' => $account,
            'overview' => $summary,
            'tabs' => $tabs,
            'tab' => $tab,
            'invoices' => $invoices->handle($account),
            'costs' => $costs->handle($account, $summary),
            'canManage' => $user->can('manageBilling', $account),
            'checkout' => $request->query('checkout'),
            'referrals' => $referrals->summary($account),
            'interval' => (string) (BillingAccount::query()->whereKey($account->id)->value('interval') ?? 'month'),
        ]);
    }
}
