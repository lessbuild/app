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

    public int $tries = 1;

    public int $timeout = AlertDeliveryQueue::TIMEOUT;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $deliveryId, public readonly int $generation) {}

    public function handle(AlertDeliveryRunner $runner): void
    {
        $runner->process($this->deliveryId, $this->generation);
    }

    public function failed(?Throwable $exception): void
    {
        app(AlertDeliveryRunner::class)->interrupted($this->deliveryId, $this->generation);
    }
}
