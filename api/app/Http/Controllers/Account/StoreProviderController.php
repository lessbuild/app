<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Infrastructure\SaveProvider;
use App\Http\Attributes\CurrentAccount;
use App\Http\Requests\Infrastructure\ProviderRequest;
use App\Models\Account;
use App\Models\User;
use App\Support\SetupGuideReturn;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class StoreProviderController
{
    /**
     * Connect a new provider and suggests checking its connection.
     *
     * @param  Account  $account
     * @param  ProviderRequest  $request
     * @param  User  $user
     * @param  SaveProvider  $save
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, ProviderRequest $request, #[CurrentUser] User $user, SaveProvider $save): JsonResponse
    {
        $provider = $save->handle($account, $user, $request->validated());

        // Connected from the setup guide: back to it, for the next step.
        if (($guide = SetupGuideReturn::from($request)) !== null) {
            return response()->json(['redirect' => $guide, 'message' => __('Provider connected. On to the next step.')]);
        }

        return response()->json(['redirect' => route('account.providers.show', $provider->id, false), 'message' => __('Provider connected. Check the connection to confirm the token works.')]);
    }
}
