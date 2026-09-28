<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Contracts\PaymentProvider;
use App\Exceptions\BillingRuleViolation;
use App\Models\Account;
use App\Models\BillingAccount;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class OpenBillingPortal
{
    /**
     * Sends the account to the provider's billing portal.
     *
     * @param  PaymentProvider  $provider  Creates the portal link.
     */
    public function __construct(private readonly PaymentProvider $provider) {}

    /**
     * Stripe's portal for payment methods, billing details and receipts.
     *
     * @param  User  $actor
     * @param  Account  $account
     * @param  string  $returnUrl
     * @return string
     */
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
