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
use Illuminate\Http\JsonResponse;

final class UpdateProviderController
{
    /**
     * Change a provider's name, credential or check settings.
     *
     * @param  Account  $account
     * @param  ProviderRequest  $request
     * @param  User  $user
     * @param  Provider  $provider
     * @param  SaveProvider  $save
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, ProviderRequest $request, #[CurrentUser] User $user, Provider $provider, SaveProvider $save): JsonResponse
    {
        $record = $save->handle($account, $user, $request->validated(), $provider);

        return response()->json(['redirect' => route('account.providers.show', $record->id, false), 'message' => __('Provider saved.')]);
    }
}
