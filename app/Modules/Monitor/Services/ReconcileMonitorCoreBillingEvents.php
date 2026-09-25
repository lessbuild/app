<?php

namespace App\Modules\Monitor\Services;

use App\Core\Models\ProductBillingEvent;
use Illuminate\Support\Collection;
use Throwable;

final readonly class ReconcileMonitorCoreBillingEvents
{
    private const PROVIDER_ACCOUNT = 'monitor';

    private const MAX_AUTOMATIC_ATTEMPTS = 5;

    public function __construct(
        private StripeBillingClient $stripe,
        private SyncMonitorBillingEventIntoCore $projection,
    ) {}

    /**
     * Retry Core projection for preserved Stripe event IDs without copying full payloads into Core.
     *
     * @return array{attempted: int, completed: int, pending: int, needs_review: int, failed: int, skipped: int}
     */
    public function handle(int $limit = 10, ?string $eventId = null, bool $includeReview = false): array
    {
        $limit = max(1, min(100, $limit));
        $summary = ['attempted' => 0, 'completed' => 0, 'pending' => 0, 'needs_review' => 0, 'failed' => 0, 'skipped' => 0];
        $events = $this->eventsToRetry($limit, $eventId, $includeReview);

        foreach ($events as $billingEvent) {
            if (! in_array($billingEvent->processing_status, ['pending_reconciliation', 'needs_review'], true)) {
                $summary['skipped']++;

                continue;
            }

            $summary['attempted']++;
            $attemptNumber = $this->attemptCount($billingEvent) + 1;

            try {
                $event = $this->stripe->retrieveEvent($billingEvent->provider_event_id);
                $this->projection->handle($event);
                $billingEvent->refresh();
                $status = $billingEvent->processing_status;

                if (in_array($status, ['pending_reconciliation', 'needs_review'], true)) {
                    $this->recordAttempt($billingEvent, $attemptNumber);
                    $summary[$status === 'pending_reconciliation' ? 'pending' : 'needs_review']++;
                } else {
                    $summary['completed']++;
                }
            } catch (Throwable $exception) {
                report($exception);
                $this->recordAttempt($billingEvent, $attemptNumber);
                $summary['failed']++;
            }
        }

        return $summary;
    }

    /** @return Collection<int, ProductBillingEvent> */
    private function eventsToRetry(int $limit, ?string $eventId, bool $includeReview): Collection
    {
        $query = ProductBillingEvent::query()
            ->where('product', 'monitor')
            ->where('provider', 'stripe')
            ->where('provider_account_key', self::PROVIDER_ACCOUNT);

        if ($eventId !== null) {
            return $query->where('provider_event_id', $eventId)->limit(1)->get();
        }

        $query->whereIn('processing_status', $includeReview
            ? ['pending_reconciliation', 'needs_review']
            : ['pending_reconciliation'])
            ->where('processed_at', '<=', now('UTC')->subMinutes(15))
            ->orderBy('id');

        $events = collect();
        $query->chunkById(100, function (Collection $batch) use (&$events, $limit): bool {
            foreach ($batch as $event) {
                if ($this->attemptCount($event) < self::MAX_AUTOMATIC_ATTEMPTS) {
                    $events->push($event);
                }

                if ($events->count() >= $limit) {
                    return false;
                }
            }

            return true;
        }, 'id');

        return $events;
    }

    private function attemptCount(ProductBillingEvent $event): int
    {
        $metadata = is_array($event->metadata) ? $event->metadata : [];

        return is_numeric($metadata['reconciliation_attempts'] ?? null)
            ? max(0, (int) $metadata['reconciliation_attempts'])
            : 0;
    }

    private function recordAttempt(ProductBillingEvent $event, int $attemptNumber): void
    {
        $metadata = is_array($event->metadata) ? $event->metadata : [];
        $metadata['reconciliation_attempts'] = max($attemptNumber, is_numeric($metadata['reconciliation_attempts'] ?? null)
            ? max(0, (int) $metadata['reconciliation_attempts'])
            : 0);

        $event->forceFill([
            'metadata' => $metadata,
            'processed_at' => now('UTC'),
        ])->save();
    }
}
