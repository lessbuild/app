<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Contracts\ProjectConnectionOutboxDispatcher;
use App\Core\Data\Connections\ProjectConnectionOutboxEvent;
use App\Core\Services\Connections\DispatchMonitorIncidentOutboxEvent;

final class MonitorProjectConnectionOutboxDispatcher implements ProjectConnectionOutboxDispatcher
{
    public function __construct(private readonly DispatchMonitorIncidentOutboxEvent $dispatcher) {}

    public function product(): string
    {
        return 'monitor';
    }

    public function dispatch(ProjectConnectionOutboxEvent $event): int
    {
        return $this->dispatcher->dispatch($event);
    }

    public function missingDeliveryCount(ProjectConnectionOutboxEvent $event): int
    {
        return $this->dispatcher->missingDeliveryCount($event);
    }
}
