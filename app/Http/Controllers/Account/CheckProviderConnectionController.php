<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Models\User;
use App\Queries\Infrastructure\ProvidersQuery;
use App\Services\Infrastructure\ProviderHealthMonitor;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

final class CheckProviderConnectionController
{
    public function __invoke(#[CurrentUser] User $user, string $provider, ProvidersQuery $providers, ProviderHealthMonitor $monitor): RedirectResponse
    {
        $account = $user->currentAccount ?? abort(404);
        Gate::authorize('update', $account);
        $result = $monitor->check($providers->find($account->id, $provider));

        return to_route('account.providers.show', (int) $provider)->with($result['successful'] ? 'status' : 'error', $result['message']);
    }
}
