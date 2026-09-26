<?php

namespace App\Modules\Monitor\Services;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessAlertDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = AlertDeliveryQueue::TIMEOUT;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $deliveryId, public readonly int $generation) {}

    public function handle(DeliverAlertNotification $processor): void
    {
        $processor->process($this->deliveryId, $this->generation);
    }

    public function failed(?Throwable $exception): void
    {
        app(DeliverAlertNotification::class)->interrupted($this->deliveryId, $this->generation);
    }
}
