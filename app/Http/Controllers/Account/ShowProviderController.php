<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Enums\ProviderType;
use App\Models\Provider;
use App\Models\User;
use App\Queries\Infrastructure\ProvidersQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

final class ShowProviderController
{
    public function __invoke(#[CurrentUser] User $user, string $provider, ProvidersQuery $providers): View
    {
        $account = $user->currentAccount ?? abort(404);
        Gate::authorize('update', $account);
        $record = $providers->find($account->id, $provider);

        return view('account.provider', [
            'account' => $account,
            'provider' => $record,
            'checks' => $record->connectionChecks()->orderByDesc('checked_at')->orderByDesc('id')->limit(20)->get(),
            'servers' => $record->servers()->orderBy('name')->get(),
            'types' => ProviderType::cases(),
            'intervals' => Provider::CHECK_INTERVALS,
            'thresholds' => Provider::FAILURE_THRESHOLDS,
        ]);
    }
}
