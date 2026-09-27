<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Actions\Billing\OpenBillingPortal;
use App\Exceptions\PaymentProviderUnavailable;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class OpenBillingPortalController
{
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, OpenBillingPortal $portal): RedirectResponse
    {
        try {
            return redirect()->away($portal->handle($user, $account, route('account.billing')));
        } catch (PaymentProviderUnavailable $unavailable) {
            return to_route('account.billing')->withErrors(['portal' => $unavailable->getMessage()]);
        }
    }
}
