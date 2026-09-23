<?php

namespace App\Core\Data\Connections;

final readonly class ProjectConnectionDeliveryAuthority
{
    public function __construct(
        public string $deliveryId,
        public string $connectionId,
        public string $capability,
    ) {}
}
