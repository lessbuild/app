<?php

namespace App\Modules\Analytics\Services\Billing;

use App\Core\Models\ProductBillingEvent;
use Throwable;

/** Bounded retry of verified event IDs kept pending after provider or mapping failure. */
final class ReconcileAnalyticsBillingEvents
{
    public function __construct(
        private readonly AnalyticsStripeClient $stripe,
        private readonly ProcessAnalyticsBillingWebhook $processor,
    ) {}

    /** @return array{attempted:int,completed:int,failed:int,remaining:int} */
    public function handle(int $limit = 10, ?string $eventId = null): array
    {
        $accountId = config('analytics.billing.stripe.account_id');
        if (! config('analytics.billing.webhooks_enabled', false)
            || ! is_string($accountId) || $accountId === '' || ! $this->stripe->configured()) {
            return ['attempted' => 0, 'completed' => 0, 'failed' => 0, 'remaining' => 0];
        }

        $query = ProductBillingEvent::query()
            ->where('product', 'analytics')
            ->where('provider', 'stripe')
            ->where('provider_account_key', $accountId)
            ->where('processing_status', 'pending');
        if ($eventId !== null) {
            $query->where('provider_event_id', $eventId);
        } else {
            $query->where('updated_at', '<=', now('UTC'));
        }

        $events = $query->orderBy('updated_at')->limit(max(1, min($limit, 100)))->get();
        $summary = ['attempted' => 0, 'completed' => 0, 'failed' => 0, 'remaining' => 0];

        foreach ($events as $receipt) {
            $summary['attempted']++;
            try {
                $event = $this->stripe->retrieveEvent($receipt->provider_event_id);
                $this->processor->handle($event);
                $summary['completed']++;
            } catch (Throwable $exception) {
                report($exception);
                $summary['failed']++;
                // Defer failures so unavailable provider events cannot starve
                // the bounded scheduled batch. An explicit event-ID replay
                // bypasses this delay for operator recovery.
                ProductBillingEvent::query()->whereKey($receipt->getKey())
                    ->where('processing_status', 'pending')
                    ->update(['updated_at' => now('UTC')->addMinutes(30)]);
            }
        }

        $summary['remaining'] = ProductBillingEvent::query()
            ->where('product', 'analytics')
            ->where('provider', 'stripe')
            ->where('provider_account_key', $accountId)
            ->where('processing_status', 'pending')
            ->count();

        return $summary;
    }
}
