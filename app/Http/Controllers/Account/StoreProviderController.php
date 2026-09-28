<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Infrastructure\SaveProvider;
use App\Http\Attributes\CurrentAccount;
use App\Http\Requests\Infrastructure\ProviderRequest;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class StoreProviderController
{
    /**
     * Connect a new provider and suggests checking its connection.
     *
     * @param  Account  $account
     * @param  ProviderRequest  $request
     * @param  User  $user
     * @param  SaveProvider  $save
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, ProviderRequest $request, #[CurrentUser] User $user, SaveProvider $save): RedirectResponse
    {
        $provider = $save->handle($account, $user, $request->validated());

        return to_route('account.providers.show', $provider->id)->with('status', __('Provider connected. Check the connection to confirm the token works.'));
    }
}
