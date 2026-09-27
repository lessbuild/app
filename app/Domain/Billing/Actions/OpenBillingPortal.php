<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Billing\Contracts\PaymentProvider;
use App\Domain\Billing\Exceptions\BillingRuleViolation;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Gate;

final class OpenBillingPortal
{
    public function __construct(private readonly PaymentProvider $provider) {}

    /** Stripe's portal for payment methods, billing details and receipts. */
    public function handle(User $actor, Account $account, string $returnUrl): string
    {
        Gate::forUser($actor)->authorize('manageBilling', $account);
        $customer = BillingAccount::query()->whereKey($account->id)->value('stripe_customer_id');
        if (! is_string($customer)) {
            throw new BillingRuleViolation('portal', __('There is nothing to manage until a paid plan is started.'));
        }

        return $this->provider->portalUrl($customer, $returnUrl);
    }
}
