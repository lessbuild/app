<?php

declare(strict_types=1);

namespace App\Data\Billing;

final readonly class WebhookEvent
{
    /**
     * A verified payment-provider webhook.
     *
     * @param  string  $id  The event's ID, recorded so a redelivered event is handled once.
     * @param  string  $type  The event type, such as `customer.subscription.updated`.
     * @param  array<string, mixed>  $object  the event's data.object
     */
    public function __construct(
        public string $id,
        public string $type,
        public array $object,
    ) {}
}
