<?php

declare(strict_types=1);

namespace App\Domain\Billing\Data;

final readonly class WebhookEvent
{
    /** @param array<string, mixed> $object the event's data.object */
    public function __construct(
        public string $id,
        public string $type,
        public array $object,
    ) {}
}
