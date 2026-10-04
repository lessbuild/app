<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\SetPayAsYouGo;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UpdatePayAsYouGoController
{
    /**
     * Turn pay-as-you-go on or off for one of a service's meters, with an optional monthly spend cap in whole dollars.
     *
     * @param  Request  $request
     * @param  Account  $account
     * @param  User  $user
     * @param  string  $service
     * @param  SetPayAsYouGo  $set
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentAccount] Account $account, #[CurrentUser] User $user, string $service, SetPayAsYouGo $set): JsonResponse
    {
        $data = $request->validate([
            'meter' => ['required', 'string', 'max:60', 'starts_with:'.$service.'.'],
            'enabled' => ['required', 'boolean'],
            'cap' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
        $enabled = $request->boolean('enabled');
        $set->handle($user, $account, $data['meter'], $enabled, $request->filled('cap') ? $request->integer('cap') * 100 : null);

        return response()->json(['redirect' => route('account.billing', ['tab' => $service], false), 'message' => $enabled ? __('Pay as you go is on: usage past your allowance is billed instead of stopping.') : __('Pay as you go is off: usage stops at your allowance.')]);
    }
}
