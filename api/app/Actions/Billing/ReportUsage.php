<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Contracts\PaymentProvider;
use App\Enums\SelectionKind;
use App\Models\Account;
use App\Models\BillingAccount;
use App\Models\BillingSelection;
use App\Models\UsageOverageReport;
use App\Models\UsageRecord;
use App\Services\Billing\Overage;
use Carbon\CarbonImmutable;

final class ReportUsage
{
    /**
     * Create a new ReportUsage instance.
     *
     * Reports metered usage beyond each account's allowance to the payment provider.
     *
     * @param  PaymentProvider  $provider  Receives the usage.
     * @param  Overage  $overage  Finds each meter and the account's allowance.
     */
    public function __construct(private readonly PaymentProvider $provider, private readonly Overage $overage) {}

    /**
     * Send Stripe the usage beyond the allowance that it hasn't heard about yet, for every account paying as it goes,
     * this month and last (so the last hours of a month still reach its invoice). Returns how many reports were sent.
     *
     * @return int
     */
    public function handle(): int
    {
        if (! $this->provider->available()) {
            return 0;
        }

        $sent = 0;
        $now = CarbonImmutable::now('UTC');
        BillingSelection::query()->where('kind', SelectionKind::Usage)->orderBy('id')->each(function (BillingSelection $selection) use ($now, &$sent): void {
            $found = $this->overage->meter($selection->item_key);
            $customer = BillingAccount::query()->whereKey($selection->account_id)->value('stripe_customer_id');
            $account = Account::query()->find($selection->account_id);
            if ($found === null || $found[1]->stripeEventName === null || ! is_string($customer) || $account === null) {
                return;
            }
            $allowance = $this->overage->allowance($account, $found[1]);
            if ($allowance === null) {
                return;
            }
            foreach ([$now->subMonthNoOverflow()->startOfMonth(), $now->startOfMonth()] as $month) {
                $used = (int) UsageRecord::query()->where('account_id', $account->id)->where('meter', $selection->item_key)
                    ->where('period_start', '>=', $month)->where('period_start', '<', $month->addMonthNoOverflow())->sum('quantity');
                $report = UsageOverageReport::query()->where('account_id', $account->id)->where('meter', $selection->item_key)
                    ->whereDate('period_start', $month->toDateString())->first() ?? new UsageOverageReport;
                $over = max(0, $used - $allowance);
                $delta = $over - (int) $report->reported;
                if ($delta <= 0) {
                    continue;
                }
                // The key covers exactly this step up, so a retry after a crash can't double-count.
                $this->provider->reportUsage($customer, $found[1]->stripeEventName, $delta, $month->isSameMonth($now) ? $now : $month->endOfMonth(), "{$account->id}:{$selection->item_key}:{$month->format('Y-m')}:{$over}");
                $report->forceFill(['account_id' => $account->id, 'meter' => $selection->item_key, 'period_start' => $month->toDateString(), 'reported' => $over])->save();
                $sent++;
            }
        });

        return $sent;
    }
}
