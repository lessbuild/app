<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Infrastructure\SaveProvider;
use App\Http\Attributes\CurrentAccount;
use App\Http\Requests\Infrastructure\ProviderRequest;
use App\Models\Account;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class UpdateProviderController
{
    /**
     * Changes a provider's name, credential or check settings.
     *
     * @param  Account  $account
     * @param  ProviderRequest  $request
     * @param  User  $user
     * @param  Provider  $provider
     * @param  SaveProvider  $save
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, ProviderRequest $request, #[CurrentUser] User $user, Provider $provider, SaveProvider $save): RedirectResponse
    {
        $record = $save->handle($account, $user, $request->validated(), $provider);

        return to_route('account.providers.show', $record->id)->with('status', __('Provider saved.'));
    }
}
