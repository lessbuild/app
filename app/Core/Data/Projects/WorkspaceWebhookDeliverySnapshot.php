<?php

namespace App\Core\Data\Projects;

use Illuminate\Support\Collection;

/** @param Collection<int, WorkspaceWebhookDelivery> $deliveries */
final readonly class WorkspaceWebhookDeliverySnapshot
{
    public function __construct(
        public Collection $deliveries,
        public bool $available = true,
    ) {}
}
