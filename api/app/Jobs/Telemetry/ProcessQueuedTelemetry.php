<?php

declare(strict_types=1);

namespace App\Jobs\Telemetry;

use App\Services\Telemetry\ProcessTelemetryReceipt;
use App\Services\Telemetry\TelemetryQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class ProcessQueuedTelemetry implements ShouldQueue
{
    use Queueable;

    /**
     * How many times a queued batch is attempted before its receipt is marked failed.
     *
     * @var int
     */
    public int $tries = TelemetryQueue::MAX_ATTEMPTS;

    /**
     * How long one attempt may run.
     *
     * @var int
     */
    public int $timeout = TelemetryQueue::TIMEOUT;

    /**
     * Create a new ProcessQueuedTelemetry instance.
     *
     * Processes a telemetry batch that was accepted for later processing.
     *
     * @param  string  $receiptId  The receipt the batch was stored under.
     * @param  int  $generation  The receipt's generation when this job was queued. A retried receipt gets a new generation, so a stale job finds nothing to do.
     */
    public function __construct(public readonly string $receiptId, public readonly int $generation) {}

    /**
     * Get the seconds to wait between attempts, growing each time.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return TelemetryQueue::BACKOFF;
    }

    /**
     * Process the batch. When the processor asks to wait, the job goes back on the queue for that many seconds.
     *
     * @param  ProcessTelemetryReceipt  $processor
     * @return void
     */
    public function handle(ProcessTelemetryReceipt $processor): void
    {
        $delay = $processor->process($this->receiptId, $this->generation);

        if ($delay !== null) {
            $this->release($delay);
        }
    }

    /**
     * Mark the receipt failed once attempts run out.
     *
     * @param  Throwable|null  $exception
     * @return void
     */
    public function failed(?Throwable $exception): void
    {
        app(ProcessTelemetryReceipt::class)->failed($this->receiptId, $this->generation);
    }
}
