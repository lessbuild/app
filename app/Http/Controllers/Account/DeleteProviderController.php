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
     * Disconnects a provider from the account.
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, Provider $provider, DeleteProvider $delete): RedirectResponse
    {
        $delete->handle($account, $user, $provider);

        return to_route('account.providers')->with('status', __('Provider removed.'));
    }
}
