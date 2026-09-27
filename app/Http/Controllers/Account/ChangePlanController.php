<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Billing\ChangeServiceTier;
use App\Exceptions\PaymentProviderUnavailable;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ChangePlanController
{
    /**
     * Moves one service to another tier. Depending on what changed, the person is sent to checkout, told the change
     * happens at the end of the paid period, or told it's done; when payments are unavailable they're told why.
     */
    public function __invoke(#[CurrentAccount] Account $account, Request $request, #[CurrentUser] User $user, string $service, ChangeServiceTier $change): RedirectResponse
    {
        $validated = $request->validate(['tier' => ['required', 'string', 'max:40']]);
        try {
            $result = $change->handle($user, $account, $service, $validated['tier'], route('account.billing'));
        } catch (PaymentProviderUnavailable $unavailable) {
            return to_route('account.billing')->withErrors(['tier' => $unavailable->getMessage()]);
        }

        return match ($result->outcome) {
            'checkout' => redirect()->away((string) $result->checkoutUrl),
            'scheduled' => to_route('account.billing')->with('status', __('Your plan stays as it is until :date, then moves to the free plan.', ['date' => $result->effectiveAt?->toFormattedDateString()])),
            'unchanged' => to_route('account.billing'),
            default => to_route('account.billing')->with('status', __('Plan changed. The difference is prorated on your next invoice.')),
        };
    }
}
