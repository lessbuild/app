<?php

declare(strict_types=1);

namespace App\Platform\Catalog;

final readonly class Meter
{
    /**
     * Create a new Meter instance.
     *
     * Usage the platform counts each month, such as telemetry events.
     *
     * @param  string  $key  Stable identifier for the usage records.
     * @param  string  $name  Shown on the usage section of the billing page.
     * @param  string  $unit  What one counted item is called, for display ("events").
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
