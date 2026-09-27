<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Infrastructure\SaveProvider;
use App\Http\Requests\Infrastructure\ProviderRequest;
use App\Models\User;
use App\Queries\Infrastructure\ProvidersQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class UpdateProviderController
{
    public function __invoke(ProviderRequest $request, #[CurrentUser] User $user, string $provider, ProvidersQuery $providers, SaveProvider $save): RedirectResponse
    {
        $account = $user->currentAccount ?? abort(404);
        $record = $save->handle($account, $user, $request->validated(), $providers->find($account->id, $provider));

        return to_route('account.providers.show', $record->id)->with('status', __('Provider saved.'));
    }
}
