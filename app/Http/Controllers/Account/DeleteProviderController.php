<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Infrastructure\DeleteProvider;
use App\Models\User;
use App\Queries\Infrastructure\ProvidersQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteProviderController
{
    public function __invoke(#[CurrentUser] User $user, string $provider, ProvidersQuery $providers, DeleteProvider $delete): RedirectResponse
    {
        $account = $user->currentAccount ?? abort(404);
        $delete->handle($account, $user, $providers->find($account->id, $provider));

        return to_route('account.providers')->with('status', __('Provider removed.'));
    }
}
