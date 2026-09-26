<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Contracts\ProjectConnectionOutboxDispatcher;
use App\Core\Data\Connections\ProjectConnectionOutboxEvent;
use App\Core\Services\Connections\DispatchDeploymentSucceededOutboxEvent;

final class DeployerProjectConnectionOutboxDispatcher implements ProjectConnectionOutboxDispatcher
{
    public function __construct(private readonly DispatchDeploymentSucceededOutboxEvent $dispatcher) {}

    public function product(): string
    {
        return 'deployer';
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
