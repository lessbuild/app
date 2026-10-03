<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Enums\ProviderType;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowProviderController
{
    /**
     * Show one provider's page: its recent connection checks, its servers, and its settings.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  Provider  $provider
     * @return View
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, Provider $provider): View
    {

        return view('account.provider', [
            'account' => $account,
            'provider' => $provider,
            'checks' => $provider->connectionChecks()->orderByDesc('checked_at')->orderByDesc('id')->limit(20)->get(),
            'servers' => $provider->servers()->orderBy('name')->get(),
            'types' => ProviderType::cases(),
            'intervals' => Provider::CHECK_INTERVALS,
            'thresholds' => Provider::FAILURE_THRESHOLDS,
        ]);
    }
}
