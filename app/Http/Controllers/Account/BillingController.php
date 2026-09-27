<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Accounts\Models\Account;
use App\Domain\Billing\Actions\ChangeServiceTier;
use App\Domain\Billing\Actions\OpenBillingPortal;
use App\Domain\Billing\Actions\ResumeServiceTier;
use App\Domain\Billing\Exceptions\PaymentProviderUnavailable;
use App\Domain\Billing\Queries\BillingOverviewQuery;
use App\Domain\Billing\Queries\InvoicesQuery;
use App\Domain\Identity\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class BillingController
{
    public function index(Request $request, #[CurrentUser] User $user, BillingOverviewQuery $overview, InvoicesQuery $invoices): View
    {
        $account = $this->account($user);
        Gate::authorize('viewBilling', $account);

        return view('account.billing', [
            'account' => $account,
            'overview' => $overview->handle($account),
            'invoices' => $invoices->handle($account),
            'canManage' => $user->can('manageBilling', $account),
            'checkout' => $request->query('checkout'),
        ]);
    }

    public function changeTier(Request $request, #[CurrentUser] User $user, string $service, ChangeServiceTier $change): RedirectResponse
    {
        $validated = $request->validate(['tier' => ['required', 'string', 'max:40']]);
        try {
            $result = $change->handle($user, $this->account($user), $service, $validated['tier'], route('account.billing'));
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

    public function resume(#[CurrentUser] User $user, string $service, ResumeServiceTier $resume): RedirectResponse
    {
        $resume->handle($user, $this->account($user), $service);

        return to_route('account.billing')->with('status', __('Your plan will carry on as before.'));
    }

    public function portal(#[CurrentUser] User $user, OpenBillingPortal $portal): RedirectResponse
    {
        try {
            return redirect()->away($portal->handle($user, $this->account($user), route('account.billing')));
        } catch (PaymentProviderUnavailable $unavailable) {
            return to_route('account.billing')->withErrors(['portal' => $unavailable->getMessage()]);
        }
    }

    private function account(User $user): Account
    {
        return $user->currentAccount ?? abort(404);
    }
}
