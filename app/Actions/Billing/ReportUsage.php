<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Contracts\PaymentProvider;
use App\Models\BillingAccount;
use App\Models\UsageRecord;
use App\Platform\ServiceRegistry;

final class ReportUsage
{
    /**
     * Reports metered usage beyond each account's allowance to the payment provider.
     *
     * @param  PaymentProvider  $provider  Receives the usage.
     * @param  ServiceRegistry  $services  The meters each service bills.
     */
    public function __construct(private readonly PaymentProvider $provider, private readonly ServiceRegistry $services) {}

    /** Send usage not yet reported to Stripe meters (for meters that bill usage). Returns how many buckets were sent. */
    public function handle(): int
    {
        if (! $this->provider->available()) {
            return 0;
        }

        $events = [];
        foreach ($this->services->all() as $service) {
            foreach ($service->billing()->meters as $meter) {
                if ($meter->stripeEventName !== null) {
                    $events[$meter->key] = $meter->stripeEventName;
                }
            }
        }
        if ($events === []) {
            return 0;
        }

        $sent = 0;
        UsageRecord::query()->whereIn('meter', array_keys($events))->whereColumn('reported_quantity', '<', 'quantity')->orderBy('period_start')
            ->each(function (UsageRecord $record) use ($events, &$sent): void {
                $customer = BillingAccount::query()->whereKey($record->account_id)->value('stripe_customer_id');
                if (! is_string($customer)) {
                    return;
                }
                $delta = $record->quantity - $record->reported_quantity;
                // The idempotency key covers exactly this slice, so a retry after a crash can't double-count.
                $this->provider->reportUsage($customer, $events[$record->meter], $delta, $record->period_start, "{$record->id}:{$record->quantity}");
                $record->forceFill(['reported_quantity' => $record->quantity])->save();
                $sent++;
            });

        return $sent;
    }
}
