<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\ChangeBillingInterval;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UpdateBillingIntervalController
{
    /**
     * Switch between paying monthly and yearly, and return to billing.
     *
     * @param  Account  $account
     * @param  Request  $request
     * @param  User  $user
     * @param  ChangeBillingInterval  $change
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, Request $request, #[CurrentUser] User $user, ChangeBillingInterval $change): JsonResponse
    {
        $interval = (string) $request->validate(['interval' => ['required', 'in:month,year']])['interval'];
        $change->handle($user, $account, $interval);

        return response()->json(['redirect' => route('account.billing', [], false), 'message' => $interval === 'year' ? __('You now pay yearly, with two months free.') : __('You now pay monthly.')]);
    }
}
