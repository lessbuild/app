<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\SelectionKind;
use App\Events\Billing\ServiceTierChanged;
use App\Models\Account;
use App\Models\BillingAccount;
use App\Models\BillingSelection;
use App\Platform\ServiceRegistry;
use App\Services\Billing\SubscriptionItems;
use Illuminate\Support\Facades\DB;

final class ApplyEndedSelections
{
    /**
     * Ends paid selections whose paid period ran out after a downgrade.
     *
     * @param  SubscriptionItems  $items  Syncs the subscription once they're removed.
     * @param  ServiceRegistry  $services  Finds each service's free tier.
     */
    public function __construct(private readonly SubscriptionItems $items, private readonly ServiceRegistry $services) {}

    /** Remove selections whose paid period has ended and update the subscriptions. Returns how many ended. */
    public function handle(): int
    {
        $ended = BillingSelection::query()->whereNotNull('ends_at')->where('ends_at', '<=', now())->get();
        foreach ($ended->groupBy('account_id') as $accountId => $selections) {
            DB::transaction(function () use ($accountId, $selections): void {
                BillingSelection::query()->whereKey($selections->modelKeys())->delete();
                $billing = BillingAccount::query()->find($accountId);
                if ($billing !== null) {
                    $this->items->sync($billing);
                }
            });
            $account = Account::query()->find($accountId);
            foreach ($selections as $selection) {
                if ($account !== null && $selection->kind === SelectionKind::Tier) {
                    $free = $this->services->find($selection->service)?->billing()->defaultTier()->key ?? 'free';
                    ServiceTierChanged::dispatch($account, $selection->service, $selection->item_key, $free, null);
                }
            }
        }

        return $ended->count();
    }
}
