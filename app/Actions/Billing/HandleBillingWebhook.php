<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Contracts\PaymentProvider;
use App\Data\Billing\WebhookEvent;
use App\Enums\SelectionKind;
use App\Events\Billing\ServiceTierChanged;
use App\Models\Account;
use App\Models\BillingAccount;
use App\Models\BillingSelection;
use App\Platform\ServiceRegistry;
use App\Services\Billing\SubscriptionItems;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class HandleBillingWebhook
{
    /**
     * Applies payment-provider webhooks to the account's billing.
     *
     * @param  PaymentProvider  $provider  Reads the subscription a checkout created.
     * @param  SubscriptionItems  $items  Stores the provider's IDs for each subscription item.
     * @param  ServiceRegistry  $services  Finds each service's free tier when selections end.
     */
    public function __construct(
        private readonly PaymentProvider $provider,
        private readonly SubscriptionItems $items,
        private readonly ServiceRegistry $services,
    ) {}

    /** Apply a verified Stripe event once. Returns false for duplicates and events we don't use. */
    public function handle(WebhookEvent $event): bool
    {
        // ON CONFLICT DO NOTHING: a retried delivery is skipped without an error that would abort a PostgreSQL transaction.
        if (DB::table('billing_webhook_events')->insertOrIgnore(['id' => $event->id, 'type' => $event->type, 'processed_at' => now()]) === 0) {
            return false;
        }

        return match ($event->type) {
            'checkout.session.completed' => $this->checkoutCompleted($event->object),
            'customer.subscription.updated', 'customer.subscription.created' => $this->subscriptionUpdated($event->object),
            'customer.subscription.deleted' => $this->subscriptionDeleted($event->object),
            default => false,
        };
    }

    /**
     * Records the subscription a checkout created and turns its items into the account's selections. Returns false when
     * the event can't be matched to an account.
     *
     * @param  array<string, mixed>  $session
     */
    private function checkoutCompleted(array $session): bool
    {
        $accountId = $session['client_reference_id'] ?? ($session['metadata']['account_id'] ?? null);
        $subscriptionId = $session['subscription'] ?? null;
        $account = is_string($accountId) ? Account::query()->find($accountId) : null;
        if ($account === null || ! is_string($subscriptionId)) {
            return false;
        }

        $state = $this->provider->subscription($subscriptionId);
        $changes = DB::transaction(function () use ($account, $session, $subscriptionId, $state): array {
            $billing = BillingAccount::forAccount($account->id);
            $billing->forceFill([
                'stripe_customer_id' => is_string($session['customer'] ?? null) ? $session['customer'] : $billing->stripe_customer_id,
                'stripe_subscription_id' => $subscriptionId,
                'status' => $state->status,
                'current_period_end' => $state->currentPeriodEnd,
            ])->save();

            $changes = [];
            foreach (array_keys($state->itemIds) as $reference) {
                $parsed = SubscriptionItems::parse($reference);
                if ($parsed === null) {
                    continue;
                }
                [$service, $kind, $itemKey] = $parsed;
                $selection = BillingSelection::query()->where('account_id', $account->id)->where('service', $service)->where('kind', $kind)
                    ->when($kind === SelectionKind::AddOn, fn ($query) => $query->where('item_key', $itemKey))
                    ->first() ?? (new BillingSelection)->forceFill(['account_id' => $account->id, 'service' => $service, 'kind' => $kind]);
                $from = $selection->exists ? $selection->item_key : ($this->services->find($service)?->billing()->defaultTier()->key ?? 'free');
                $selection->forceFill(['item_key' => $itemKey, 'ends_at' => null])->save();
                if ($kind === SelectionKind::Tier && $from !== $itemKey) {
                    $changes[] = [$service, $from, $itemKey];
                }
            }
            $this->items->storeItemIds($account->id, $state->itemIds);

            return $changes;
        });

        foreach ($changes as [$service, $from, $to]) {
            ServiceTierChanged::dispatch($account, $service, $from, $to, null);
        }

        return true;
    }

    /**
     * Copies the subscription's status and period end.
     *
     * @param  array<string, mixed>  $subscription
     */
    private function subscriptionUpdated(array $subscription): bool
    {
        $billing = $this->billingFor($subscription);
        if ($billing === null) {
            return false;
        }
        $billing->forceFill([
            'status' => is_string($subscription['status'] ?? null) ? $subscription['status'] : $billing->status,
            'current_period_end' => self::periodEnd($subscription) ?? $billing->current_period_end,
        ])->save();

        return true;
    }

    /**
     * Clears the subscription and every selection, putting each service back on its free tier.
     *
     * @param  array<string, mixed>  $subscription
     */
    private function subscriptionDeleted(array $subscription): bool
    {
        $billing = $this->billingFor($subscription);
        if ($billing === null) {
            return false;
        }

        $removed = DB::transaction(function () use ($billing) {
            $billing->forceFill(['status' => 'canceled', 'stripe_subscription_id' => null, 'current_period_end' => null])->save();
            $removed = BillingSelection::query()->where('account_id', $billing->account_id)->get();
            BillingSelection::query()->whereKey($removed->modelKeys())->delete();

            return $removed;
        });

        $account = Account::query()->find($billing->account_id);
        foreach ($removed->where('kind', SelectionKind::Tier) as $selection) {
            if ($account !== null) {
                ServiceTierChanged::dispatch($account, $selection->service, $selection->item_key, $this->services->find($selection->service)?->billing()->defaultTier()->key ?? 'free', null);
            }
        }

        return true;
    }

    /**
     * The billing record for a subscription in a webhook, or null when it isn't ours.
     *
     * @param  array<string, mixed>  $subscription
     */
    private function billingFor(array $subscription): ?BillingAccount
    {
        $id = $subscription['id'] ?? null;

        return is_string($id) ? BillingAccount::query()->where('stripe_subscription_id', $id)->first() : null;
    }

    /**
     * Newer Stripe API versions put the period on each item; older ones on the subscription.
     *
     * @param  array<string, mixed>  $subscription
     */
    private static function periodEnd(array $subscription): ?CarbonImmutable
    {
        $end = $subscription['items']['data'][0]['current_period_end'] ?? ($subscription['current_period_end'] ?? null);

        return is_int($end) ? CarbonImmutable::createFromTimestamp($end) : null;
    }
}
