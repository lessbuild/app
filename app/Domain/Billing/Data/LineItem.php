<?php

declare(strict_types=1);

namespace App\Domain\Billing\Data;

final readonly class LineItem
{
    public function __construct(
        /** `service:kind:item`, stored as Stripe item metadata so webhooks can map items back. */
        public string $reference,
        public string $priceId,
        public int $quantity = 1,
    ) {}
}
