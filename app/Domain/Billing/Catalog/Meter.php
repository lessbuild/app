<?php

declare(strict_types=1);

namespace App\Domain\Billing\Catalog;

final readonly class Meter
{
    /**
     * @param  string  $allowanceKey  the limit key holding each tier's monthly allowance
     * @param  string|null  $stripeEventName  Stripe billing meter event name, when usage beyond the allowance is billed
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $unit,
        public string $allowanceKey,
        public ?string $stripeEventName = null,
    ) {}
}
