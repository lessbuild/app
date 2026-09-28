<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Infrastructure\DeleteProvider;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteProviderController
{
    /**
     * Disconnect a provider from the account.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  Provider  $provider
     * @param  DeleteProvider  $delete
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, Provider $provider, DeleteProvider $delete): RedirectResponse
    {
        $delete->handle($account, $user, $provider);

        return to_route('account.providers')->with('status', __('Provider removed.'));
    }
}
