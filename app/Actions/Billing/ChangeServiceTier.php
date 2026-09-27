<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Contracts\PaymentProvider;
use App\Data\Billing\LineItem;
use App\Data\Billing\PlanChange;
use App\Enums\SelectionKind;
use App\Events\Billing\ServiceTierChanged;
use App\Exceptions\BillingRuleViolation;
use App\Models\Account;
use App\Models\BillingAccount;
use App\Models\BillingSelection;
use App\Models\User;
use App\Platform\ServiceRegistry;
use App\Services\Billing\PriceBook;
use App\Services\Billing\SubscriptionItems;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ChangeServiceTier
{
    public function __construct(
        private readonly ServiceRegistry $services,
        private readonly PriceBook $prices,
        private readonly PaymentProvider $provider,
        private readonly SubscriptionItems $items,
    ) {}

    /** Move one service to another tier. Only that service's subscription item changes. */
    public function handle(User $actor, Account $account, string $service, string $tierKey, string $returnUrl): PlanChange
    {
        Gate::forUser($actor)->authorize('manageBilling', $account);
        $billing = $this->services->find($service)?->billing() ?? throw BillingRuleViolation::unknown();
        $target = $billing->tier($tierKey) ?? throw BillingRuleViolation::unknown();
        if (! $this->prices->purchasable($service, $target)) {
            throw BillingRuleViolation::notOnSale();
        }

        $billingAccount = BillingAccount::forAccount($account->id);
        $selection = BillingSelection::query()->where('account_id', $account->id)->where('service', $service)->where('kind', SelectionKind::Tier)->first();
        $current = $selection !== null ? $billing->tier($selection->item_key) ?? $billing->defaultTier() : $billing->defaultTier();

        if ($target->isFree()) {
            return $this->downgradeToFree($actor, $account, $billingAccount, $selection, $service, $current->key, $target->key);
        }
        if ($current->key === $target->key && $selection?->ends_at === null) {
            return PlanChange::unchanged();
        }
        if (! $this->provider->available()) {
            throw BillingRuleViolation::unavailable();
        }

        if (! $billingAccount->hasLiveSubscription()) {
            $customer = $billingAccount->stripe_customer_id ?? $this->provider->createCustomer($account->id, $account->name, $actor->email);
            $billingAccount->forceFill(['stripe_customer_id' => $customer])->save();
            $price = (string) $this->prices->priceId($service, SelectionKind::Tier, $target->key);

            return PlanChange::checkout($this->provider->checkoutUrl(
                $customer,
                $account->id,
                [new LineItem(SubscriptionItems::reference($service, SelectionKind::Tier, $target->key), $price)],
                $returnUrl.'?checkout=done',
                $returnUrl.'?checkout=cancelled',
            ));
        }

        DB::transaction(function () use ($account, $selection, $service, $target, $billingAccount): void {
            $selection ??= (new BillingSelection)->forceFill(['account_id' => $account->id, 'service' => $service, 'kind' => SelectionKind::Tier]);
            $selection->forceFill(['item_key' => $target->key, 'quantity' => 1, 'ends_at' => null, 'legacy_price_id' => null, 'stripe_item_id' => null])->save();
            $this->items->sync($billingAccount);
        });
        ServiceTierChanged::dispatch($account, $service, $current->key, $target->key, $actor);

        return PlanChange::changed();
    }

    private function downgradeToFree(User $actor, Account $account, BillingAccount $billingAccount, ?BillingSelection $selection, string $service, string $from, string $to): PlanChange
    {
        if ($selection === null || $from === $to) {
            return PlanChange::unchanged();
        }
        // Keep what's been paid for until the period ends; the hourly job removes it then.
        if ($billingAccount->hasLiveSubscription() && $billingAccount->current_period_end?->isFuture()) {
            if ($selection->ends_at === null) {
                $selection->forceFill(['ends_at' => $billingAccount->current_period_end])->save();
                ServiceTierChanged::dispatch($account, $service, $from, $to, $actor, $billingAccount->current_period_end);
            }

            return PlanChange::scheduled($billingAccount->current_period_end);
        }

        DB::transaction(function () use ($selection, $billingAccount): void {
            $selection->delete();
            $this->items->sync($billingAccount);
        });
        ServiceTierChanged::dispatch($account, $service, $from, $to, $actor);

        return PlanChange::changed();
    }
}
