<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Actions\Billing\ChangeServiceTier;
use App\Exceptions\PaymentProviderUnavailable;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ChangePlanController
{
    /**
     * Move one service to another tier. Depending on what changed, the person is sent to checkout, told the change
     * happens at the end of the paid period, or told it's done; when payments are unavailable they're told why.
     *
     * @param  Account  $account
     * @param  Request  $request
     * @param  User  $user
     * @param  string  $service
     * @param  ChangeServiceTier  $change
     * @return JsonResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, Request $request, #[CurrentUser] User $user, string $service, ChangeServiceTier $change): JsonResponse
    {
        $validated = $request->validate(['tier' => ['required', 'string', 'max:40']]);
        try {
            $result = $change->handle($user, $account, $service, $validated['tier'], route('account.billing', ['tab' => $service]));
        } catch (PaymentProviderUnavailable $unavailable) {
            throw ValidationException::withMessages(['tier' => $unavailable->getMessage()]);
        }
        $back = route('account.billing', ['tab' => $service], false);

        // A first paid plan goes through the payment provider's checkout; anything else is done here.
        return response()->json(match ($result->outcome) {
            'checkout' => ['redirect' => (string) $result->checkoutUrl],
            'scheduled' => ['redirect' => $back, 'message' => __('Your plan stays as it is until :date, then moves to the free plan.', ['date' => $result->effectiveAt?->toFormattedDateString()])],
            'unchanged' => ['redirect' => $back],
            default => ['redirect' => $back, 'message' => __('Plan changed. The difference is prorated on your next invoice.')],
        });
    }
}
