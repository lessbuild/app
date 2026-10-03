<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Enums\SelectionKind;
use App\Exceptions\BillingRuleViolation;
use App\Models\Account;
use App\Models\BillingAccount;
use App\Models\BillingSelection;
use App\Models\User;
use App\Services\Billing\Overage;
use App\Services\Billing\PriceBook;
use App\Services\Billing\SubscriptionItems;
use Illuminate\Support\Facades\Gate;

final class SetPayAsYouGo
{
    /**
     * Create a new SetPayAsYouGo instance.
     *
     * @param  Overage  $overage  Finds the meter.
     * @param  PriceBook  $prices  Holds the meter's usage price.
     * @param  SubscriptionItems  $items  Adds or removes the metered item on the subscription.
     * @param  RecordAuditEntry  $audit  Records the change.
     */
    public function __construct(
        private readonly Overage $overage,
        private readonly PriceBook $prices,
        private readonly SubscriptionItems $items,
        private readonly RecordAuditEntry $audit,
    ) {}

    /**
     * Turn pay-as-you-go on for one meter (usage past the allowance is billed per unit, up to an optional monthly spend
     * cap, instead of stopping) or off again. Needs a live monthly subscription to add the metered item to.
     *
     * @param  User  $actor
     * @param  Account  $account
     * @param  string  $meterKey
     * @param  bool  $enabled
     * @param  int|null  $capCents  null for no cap
     * @return void
     */
    public function handle(User $actor, Account $account, string $meterKey, bool $enabled, ?int $capCents): void
    {
        Gate::forUser($actor)->authorize('manageBilling', $account);
        $found = $this->overage->meter($meterKey) ?? throw BillingRuleViolation::unknown();
        [$service, $meter] = $found;
        $billing = BillingAccount::forAccount($account->id);
        $selection = $this->overage->selection($account->id, $meterKey);

        if ($enabled) {
            if (! $billing->hasLiveSubscription() || $meter->stripeEventName === null || $this->prices->priceId($service, SelectionKind::Usage, $meterKey, $billing->interval) === null) {
                throw BillingRuleViolation::usageUnavailable();
            }
            ($selection ?? new BillingSelection)->forceFill([
                'account_id' => $account->id, 'service' => $service, 'kind' => SelectionKind::Usage, 'item_key' => $meterKey,
                'quantity' => 1, 'spend_cap_cents' => $capCents,
            ])->save();
        } elseif ($selection !== null) {
            $selection->delete();
        } else {
            return;
        }

        $this->items->sync($billing);
        $this->audit->handle(AuditAction::PayAsYouGoChanged, $actor, $account->id, ['meter' => $meter->name, 'enabled' => $enabled, 'cap_cents' => $capCents]);
    }
}
