<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\SelectionKind;
use App\Exceptions\BillingRuleViolation;
use App\Models\Account;
use App\Models\BillingAccount;
use App\Models\BillingSelection;
use App\Models\User;
use App\Services\Billing\PriceBook;
use App\Services\Billing\SubscriptionItems;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ChangeBillingInterval
{
    /**
     * Create a new ChangeBillingInterval instance.
     *
     * @param  PriceBook  $prices  Finds each item's price at the new interval.
     * @param  SubscriptionItems  $items  Moves a live subscription over.
     */
    public function __construct(private readonly PriceBook $prices, private readonly SubscriptionItems $items) {}

    /**
     * Pay monthly or yearly. A live subscription moves over at once (Stripe prorates), which needs every paid item to
     * have a price at the new interval; without one, the choice applies to the next checkout.
     *
     * @param  User  $actor
     * @param  Account  $account
     * @param  string  $interval  month or year
     * @return void
     */
    public function handle(User $actor, Account $account, string $interval): void
    {
        Gate::forUser($actor)->authorize('manageBilling', $account);
        $interval = $interval === 'year' ? 'year' : 'month';
        $billing = BillingAccount::forAccount($account->id);
        if ($billing->interval === $interval) {
            return;
        }
        foreach (BillingSelection::query()->where('account_id', $account->id)->where('kind', SelectionKind::Tier)->whereNull('ends_at')->get() as $selection) {
            if ($this->prices->priceId($selection->service, $selection->kind, $selection->item_key, $interval) === null) {
                throw new BillingRuleViolation('interval', __('Every paid plan needs a :interval price first; this one isn’t on sale that way yet.', ['interval' => $interval === 'year' ? __('yearly') : __('monthly')]));
            }
        }
        DB::transaction(function () use ($billing, $interval): void {
            $billing->forceFill(['interval' => $interval])->save();
            $this->items->sync($billing);
        });
    }
}
