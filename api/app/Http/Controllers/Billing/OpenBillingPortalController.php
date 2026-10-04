<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\OpenBillingPortal;
use App\Exceptions\PaymentProviderUnavailable;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class OpenBillingPortalController
{
    /**
     * Send the person to the payment provider's billing portal, or back with the reason when it's unavailable.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  OpenBillingPortal  $portal
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, OpenBillingPortal $portal): JsonResponse
    {
        try {
            return response()->json(['redirect' => $portal->handle($user, $account, route('account.billing'))]);
        } catch (PaymentProviderUnavailable $unavailable) {
            throw ValidationException::withMessages(['portal' => $unavailable->getMessage()]);
        }
    }
}
