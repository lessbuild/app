<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Billing\Contracts\PaymentProvider;
use App\Domain\Billing\Data\WebhookEvent;
use App\Domain\Billing\Enums\SelectionKind;
use App\Domain\Billing\Events\ServiceTierChanged;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Models\BillingSelection;
use App\Domain\Billing\Support\SubscriptionItems;
use App\Platform\ServiceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class HandleBillingWebhook
{
    public function __construct(
        private readonly PaymentProvider $provider,
        private readonly SubscriptionItems $items,
        private readonly ServiceRegistry $services,
    ) {}

    /** Apply a verified Stripe event once. Returns false for duplicates and events we don't use. */
    public function handle(WebhookEvent $event): bool
    {
        try {
            DB::table('billing_webhook_events')->insert(['id' => $event->id, 'type' => $event->type, 'processed_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return match ($event->type) {
            'checkout.session.completed' => $this->checkoutCompleted($event->object),
            'customer.subscription.updated', 'customer.subscription.created' => $this->subscriptionUpdated($event->object),
            'customer.subscription.deleted' => $this->subscriptionDeleted($event->object),
            default => false,
        };
    }

    /** @param array<string, mixed> $session */
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

    /** @param array<string, mixed> $subscription */
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

    /** @param array<string, mixed> $subscription */
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

    /** @param array<string, mixed> $subscription */
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
