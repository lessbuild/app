<?php

namespace App\Core\Contracts;

use App\Core\Enums\ProjectConnectionCapability;

/**
 * A product-owned handler for one versioned project-connection delivery.
 *
 * Core selects the handler from public metadata; the product module retains
 * ownership of authorization, idempotency, and its local transaction.
 */
interface ProjectConnectionDeliveryConsumer
{
    /** @return list<string> */
    public function eventTypes(): array;

    public function capability(): ProjectConnectionCapability;

    public function targetProduct(): string;

    /** @param array<string, mixed> $payload */
    public function consume(string $deliveryId, string $connectionId, array $payload): void;
}
