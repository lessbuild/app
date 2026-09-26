<?php

namespace App\Modules\Monitor\Services\Telemetry;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessQueuedTelemetry implements ShouldQueue
{
    use Queueable;

    public int $tries = TelemetryQueue::MAX_ATTEMPTS;

    public int $timeout = TelemetryQueue::TIMEOUT;

    public function __construct(public readonly string $receiptId, public readonly int $generation) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return TelemetryQueue::BACKOFF;
    }

    public function handle(ProcessTelemetryReceipt $processor): void
    {
        $delay = $processor->process($this->receiptId, $this->generation);

        if ($delay !== null) {
            $this->release($delay);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(ProcessTelemetryReceipt::class)->failed($this->receiptId, $this->generation);
    }
}
