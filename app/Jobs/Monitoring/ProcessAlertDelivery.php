<?php

declare(strict_types=1);

namespace App\Jobs\Monitoring;

use App\Services\Monitoring\AlertDeliveryQueue;
use App\Services\Monitoring\AlertDeliveryRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class ProcessAlertDelivery implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt per job: the delivery runner schedules its own retries with backoff.
     *
     * @var int
     */
    public int $tries = 1;

    /**
     * How long one delivery attempt may take.
     *
     * @var int
     */
    public int $timeout = AlertDeliveryQueue::TIMEOUT;

    /**
     * A timed-out attempt is failed and handed to the runner, which decides whether to retry.
     *
     * @var bool
     */
    public bool $failOnTimeout = true;

    /**
     * Create a new ProcessAlertDelivery instance.
     *
     * Sends one alert delivery attempt.
     *
     * @param  string  $deliveryId  The delivery.
     * @param  int  $generation  The delivery's generation when this job was queued, so a stale job can't send a delivery that was retried or cancelled since.
     */
    public function __construct(public readonly string $deliveryId, public readonly int $generation) {}

    /**
     * Hand the delivery to its destination's transport and records the outcome.
     *
     * @param  AlertDeliveryRunner  $runner
     * @return void
     */
    public function handle(AlertDeliveryRunner $runner): void
    {
        $runner->process($this->deliveryId, $this->generation);
    }

    /**
     * Record that the attempt was interrupted while sending. Webhooks carry a delivery ID receivers can deduplicate
     * on, so they're retried; other destinations are marked uncertain, since the alert may have arrived.
     *
     * @param  Throwable|null  $exception
     * @return void
     */
    public function failed(?Throwable $exception): void
    {
        app(AlertDeliveryRunner::class)->interrupted($this->deliveryId, $this->generation);
    }
}
