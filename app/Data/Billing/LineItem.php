<?php

declare(strict_types=1);

namespace App\Data\Billing;

final readonly class LineItem
{
    /**
     * Create a new LineItem instance.
     *
     * One item on the subscription: a tier, add-on or meter with its price.
     *
     * @param  string  $reference  `service:kind:item`, stored as Stripe item metadata so webhooks can map items back.
     * @param  string  $priceId  The provider's price ID.
     * @param  int  $quantity  How many units (add-ons can be bought several times).
     */
    public function __construct(
        public string $reference,
        public string $priceId,
        public int $quantity = 1,
    ) {}
}
