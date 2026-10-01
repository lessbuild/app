<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\Client;
use App\Models\Project;
use App\Services\Billing\Entitlements;
use Illuminate\Contracts\View\View;

final class ShowClientsController
{
    /**
     * Show the agency tools: white-label branding and the account's clients.
     *
     * @param  Account  $account
     * @param  Entitlements  $entitlements
     * @return View
     */
    public function __invoke(#[CurrentAccount] Account $account, Entitlements $entitlements): View
    {
        return view('account.clients', [
            'account' => $account,
            'clients' => Client::query()->where('account_id', $account->id)->orderBy('name')->get(),
            'projects' => Project::query()->where('account_id', $account->id)->where('is_sample', false)->orderBy('name')->get(),
            'whiteLabel' => $entitlements->for($account)->has('account.white_label'),
        ]);
    }
}
