<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\BillingEvent;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\Core\MonitorDeletionFence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ProcessStripeBillingEvent
{
    public function __construct(
        private readonly StripeBillingClient $stripe,
        private readonly MonitorPlanAuthority $planAuthority,
        private readonly SyncMonitorBillingEventIntoCore $coreBilling,
    ) {}

    /**
     * Process a verified Stripe event once.
     *
     * @param  array<string, mixed>  $event
     */
    public function handle(array $event): bool
    {
        $eventId = $event['id'] ?? null;
        $eventType = $event['type'] ?? null;
        $object = $event['data']['object'] ?? [];
        $stripeCreatedAt = $this->eventTimestamp($event['created'] ?? null);

        if (! is_string($eventId) || $eventId === '' || ! is_string($eventType) || $eventType === '') {
            throw new InvalidArgumentException('The Stripe event is missing its identity.');
        }

        if (! is_array($object)) {
            $object = [];
        }

        $processedLocally = DB::connection('monitor')->transaction(function () use ($eventId, $eventType, $object, $stripeCreatedAt): bool {
            $existing = BillingEvent::query()->where('stripe_event_id', $eventId)->lockForUpdate()->first();
            if ($existing !== null) {
                return false;
            }

            $workspace = $this->resolveWorkspace($object);
            if ($workspace !== null) {
                $workspace = Workspace::query()->lockForUpdate()->find($workspace->id);
            }
            if ($workspace !== null && MonitorDeletionFence::workspaceIsFenced($workspace->getKey())) {
                BillingEvent::query()->create([
                    'stripe_event_id' => $eventId,
                    'event_type' => $eventType,
                    'workspace_id' => $workspace->getKey(),
                    'stripe_created_at' => $stripeCreatedAt,
                    // Keep the source identity so Core can reconcile late financial state
                    // and block deletion when an active or unsettled obligation arrives.
                    // The native workspace itself remains fenced and is not changed here.
                    'processing_status' => BillingEvent::STATUS_PENDING,
                    'ignored_reason' => 'awaiting_core_reconciliation',
                    'processed_at' => null,
                ]);

                return true;
            }
            $billingEvent = BillingEvent::query()->create([
                'stripe_event_id' => $eventId,
                'event_type' => $eventType,
                'workspace_id' => $workspace?->getKey(),
                'stripe_created_at' => $stripeCreatedAt,
                'processing_status' => BillingEvent::STATUS_APPLIED,
                'processed_at' => now('UTC'),
            ]);

            if ($workspace !== null && $this->isStale($workspace, $stripeCreatedAt, $eventId)) {
                $billingEvent->forceFill([
                    'processing_status' => BillingEvent::STATUS_IGNORED,
                    'ignored_reason' => 'stale_event',
                ])->save();

                return true;
            }

            $this->apply($workspace, $eventType, $object);

            if ($workspace !== null) {
                $values = ['billing_event_created_at' => $stripeCreatedAt, 'billing_event_id' => $eventId];
                if ($stripeCreatedAt === null) {
                    $values = [];
                }
                if ($values !== []) {
                    $workspace->forceFill($values)->save();
                }
                if ($billingEvent->workspace_id === null) {
                    $billingEvent->forceFill(['workspace_id' => $workspace->getKey()])->save();
                }
            }

            return true;
        });

        $processedInCore = $this->planAuthority->usesCore()
            ? $this->coreBilling->handle($event)
            : false;

        return $processedLocally || $processedInCore;
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function resolveWorkspace(array $object): ?Workspace
    {
        $metadata = $object['metadata'] ?? [];
        $workspaceId = is_array($metadata) ? $metadata['workspace_id'] ?? null : null;
        $workspaceId ??= $object['client_reference_id'] ?? null;

        if (is_scalar($workspaceId) && is_numeric((string) $workspaceId)) {
            $workspace = Workspace::query()->find((int) $workspaceId);
            if ($workspace !== null) {
                return $workspace;
            }
        }

        $subscriptionId = $object['subscription'] ?? null;
        if (! is_string($subscriptionId) || $subscriptionId === '') {
            $subscriptionId = ($object['object'] ?? null) === 'subscription'
                ? (string) ($object['id'] ?? '')
                : null;
        }

        if ($subscriptionId !== null && $subscriptionId !== '') {
            $workspace = Workspace::query()->where('stripe_subscription_id', $subscriptionId)->first();
            if ($workspace !== null) {
                return $workspace;
            }
        }

        $customerId = $object['customer'] ?? null;

        return is_string($customerId) && $customerId !== ''
            ? Workspace::query()->where('stripe_customer_id', $customerId)->first()
            : null;
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function apply(?Workspace $workspace, string $eventType, array $object): void
    {
        if ($workspace === null) {
            return;
        }

        match ($eventType) {
            'checkout.session.completed' => $this->applyCheckout($workspace, $object),
            'customer.subscription.created', 'customer.subscription.updated' => $this->applySubscription($workspace, $object),
            'customer.subscription.deleted' => $this->applySubscriptionDeleted($workspace, $object),
            'invoice.paid' => $this->applyInvoicePaid($workspace, $object),
            'invoice.payment_failed' => $this->applyInvoicePaymentFailed($workspace, $object),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function applyCheckout(Workspace $workspace, array $object): void
    {
        $paymentStatus = (string) ($object['payment_status'] ?? 'pending');
        $metadata = $object['metadata'] ?? [];
        $plan = is_array($metadata) && is_string($metadata['plan'] ?? null) ? $metadata['plan'] : null;
        $values = [
            'stripe_customer_id' => $this->stringValue($object['customer'] ?? null) ?? $workspace->stripe_customer_id,
            'stripe_subscription_id' => $this->stringValue($object['subscription'] ?? null) ?? $workspace->stripe_subscription_id,
            'billing_status' => in_array($paymentStatus, ['paid', 'no_payment_required'], true) ? 'active' : 'pending',
            'billing_updated_at' => now('UTC'),
            'stripe_checkout_session_id' => null,
            'stripe_checkout_url' => null,
            'billing_checkout_plan' => null,
            'billing_checkout_started_at' => null,
        ];

        if ($plan !== null && $this->stripe->priceId($plan) !== null && in_array($paymentStatus, ['paid', 'no_payment_required'], true)) {
            $values['plan'] = $plan;
        }

        $workspace->forceFill($values)->save();
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function applySubscription(Workspace $workspace, array $object): void
    {
        $status = (string) ($object['status'] ?? 'unknown');
        $priceId = $this->subscriptionPriceId($object);
        $plan = $this->stripe->planForPrice($priceId);
        $values = [
            'stripe_customer_id' => $this->stringValue($object['customer'] ?? null) ?? $workspace->stripe_customer_id,
            'stripe_subscription_id' => $this->stringValue($object['id'] ?? null) ?? $workspace->stripe_subscription_id,
            'stripe_price_id' => $priceId ?? $workspace->stripe_price_id,
            'billing_status' => $status,
            'billing_period_ends_at' => $this->timestamp($object['current_period_end'] ?? null),
            'billing_cancel_at_period_end' => (bool) ($object['cancel_at_period_end'] ?? false),
            'billing_updated_at' => now('UTC'),
            'stripe_checkout_session_id' => null,
            'stripe_checkout_url' => null,
            'billing_checkout_plan' => null,
            'billing_checkout_started_at' => null,
        ];

        if (in_array($status, ['active', 'trialing'], true) && $plan !== null) {
            $values['plan'] = $plan;
        } elseif (in_array($status, ['canceled', 'unpaid', 'incomplete_expired'], true)) {
            $values['plan'] = 'free';
        }

        $workspace->forceFill($values)->save();
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function applySubscriptionDeleted(Workspace $workspace, array $object): void
    {
        $workspace->forceFill([
            'stripe_subscription_id' => $this->stringValue($object['id'] ?? null) ?? $workspace->stripe_subscription_id,
            'stripe_price_id' => $this->subscriptionPriceId($object) ?? $workspace->stripe_price_id,
            'billing_status' => 'canceled',
            'billing_period_ends_at' => $this->timestamp($object['current_period_end'] ?? null),
            'billing_cancel_at_period_end' => true,
            'billing_updated_at' => now('UTC'),
            'plan' => 'free',
            'stripe_checkout_session_id' => null,
            'stripe_checkout_url' => null,
            'billing_checkout_plan' => null,
            'billing_checkout_started_at' => null,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function applyInvoicePaid(Workspace $workspace, array $object): void
    {
        $workspace->forceFill([
            'stripe_customer_id' => $this->stringValue($object['customer'] ?? null) ?? $workspace->stripe_customer_id,
            'stripe_subscription_id' => $this->stringValue($object['subscription'] ?? null) ?? $workspace->stripe_subscription_id,
            'billing_status' => 'active',
            'billing_updated_at' => now('UTC'),
            'stripe_checkout_session_id' => null,
            'stripe_checkout_url' => null,
            'billing_checkout_plan' => null,
            'billing_checkout_started_at' => null,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function applyInvoicePaymentFailed(Workspace $workspace, array $object): void
    {
        $workspace->forceFill([
            'stripe_customer_id' => $this->stringValue($object['customer'] ?? null) ?? $workspace->stripe_customer_id,
            'stripe_subscription_id' => $this->stringValue($object['subscription'] ?? null) ?? $workspace->stripe_subscription_id,
            'billing_status' => 'past_due',
            'billing_updated_at' => now('UTC'),
            'stripe_checkout_session_id' => null,
            'stripe_checkout_url' => null,
            'billing_checkout_plan' => null,
            'billing_checkout_started_at' => null,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function subscriptionPriceId(array $object): ?string
    {
        $items = $object['items']['data'] ?? [];
        $priceId = is_array($items) ? ($items[0]['price']['id'] ?? null) : null;

        return $this->stringValue($priceId);
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        return is_numeric($value) ? CarbonImmutable::createFromTimestampUTC((int) $value) : null;
    }

    private function eventTimestamp(mixed $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }

    private function isStale(Workspace $workspace, ?int $stripeCreatedAt, string $eventId): bool
    {
        if ($stripeCreatedAt === null || $workspace->billing_event_created_at === null) {
            return false;
        }

        if ($stripeCreatedAt < $workspace->billing_event_created_at) {
            return true;
        }

        return $stripeCreatedAt === $workspace->billing_event_created_at
            && $workspace->billing_event_id !== null
            && strcmp($eventId, $workspace->billing_event_id) <= 0;
    }
}
