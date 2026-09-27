<?php

declare(strict_types=1);

namespace App\Queries\Billing;

use App\Contracts\PaymentProvider;
use App\Data\Billing\InvoiceSummary;
use App\Exceptions\PaymentProviderUnavailable;
use App\Models\Account;
use App\Models\BillingAccount;

final class InvoicesQuery
{
    public function __construct(private readonly PaymentProvider $provider) {}

    /** @return list<InvoiceSummary>|null null when invoices can't be loaded right now */
    public function handle(Account $account): ?array
    {
        $customer = BillingAccount::query()->whereKey($account->id)->value('stripe_customer_id');
        if (! is_string($customer)) {
            return [];
        }
        try {
            return $this->provider->invoices($customer);
        } catch (PaymentProviderUnavailable) {
            return null;
        }
    }
}
