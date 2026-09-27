<?php

declare(strict_types=1);

namespace App\Domain\Billing\Data;

use Carbon\CarbonInterface;

final readonly class PlanChange
{
    private function __construct(
        public string $outcome,
        public ?string $checkoutUrl = null,
        public ?CarbonInterface $effectiveAt = null,
    ) {}

    public static function changed(): self
    {
        return new self('changed');
    }

    public static function unchanged(): self
    {
        return new self('unchanged');
    }

    /** The paid period runs out first; the change happens then. */
    public static function scheduled(CarbonInterface $at): self
    {
        return new self('scheduled', effectiveAt: $at);
    }

    /** The first paid plan: send the person to Stripe Checkout; the webhook applies it. */
    public static function checkout(string $url): self
    {
        return new self('checkout', $url);
    }
}
