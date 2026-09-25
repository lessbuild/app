<?php

namespace App\Core\Contracts;

use App\Core\Data\Connections\ProjectConnectionOutboxEvent;

/** Product-owned event integration for Core project-connection delivery records. */
interface ProjectConnectionOutboxDispatcher
{
    public function product(): string;

    public function dispatch(ProjectConnectionOutboxEvent $event): int;

    public function missingDeliveryCount(ProjectConnectionOutboxEvent $event): int;
}
