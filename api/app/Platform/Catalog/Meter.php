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
     * @param  int  $unitSize  how many counted items one overage price covers ("per 100,000 events")
     * @param  int  $unitCents  the pay-as-you-go price of each unit beyond the allowance
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $unit,
        public string $allowanceKey,
        public ?string $stripeEventName = null,
        public int $unitSize = 1000,
        public int $unitCents = 0,
    ) {}

    /**
     * Get what pay-as-you-go usage beyond the allowance costs, rounding up to whole units.
     *
     * @param  int  $overage  counted items beyond the allowance
     * @return int cents
     */
    public function costCents(int $overage): int
    {
        return $overage <= 0 ? 0 : intdiv($overage + $this->unitSize - 1, $this->unitSize) * $this->unitCents;
    }

    /**
     * Get the most usage beyond the allowance a spend cap pays for.
     *
     * @param  int|null  $capCents  null for no cap
     * @return int|null null when there's no cap
     */
    public function overageWithin(?int $capCents): ?int
    {
        if ($capCents === null) {
            return null;
        }

        return $this->unitCents === 0 ? PHP_INT_MAX : intdiv($capCents, $this->unitCents) * $this->unitSize;
    }
}
