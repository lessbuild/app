<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Enums\ProviderType;
use App\Models\User;
use App\Queries\Infrastructure\ProvidersQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

/** The account's provider credentials: clouds for servers, Cloudflare for DNS, Git hosts for Deploy. */
final class ShowProvidersController
{
    public function __invoke(#[CurrentUser] User $user, ProvidersQuery $providers): View
    {
        $account = $user->currentAccount ?? abort(404);
        Gate::authorize('update', $account);

        return view('account.providers', ['account' => $account, 'providers' => $providers->handle($account->id), 'types' => ProviderType::cases()]);
    }
}
