<?php

declare(strict_types=1);

namespace App\Data\Billing;

use Carbon\CarbonInterface;

final readonly class PlanChange
{
    /**
     * Use the named constructors, which say what happened.
     *
     * @param  string  $outcome  `changed`, `unchanged`, `scheduled` or `checkout`.
     * @param  ?string  $checkoutUrl  Where to send the person to pay, for `checkout`.
     * @param  ?CarbonInterface  $effectiveAt  When a scheduled change happens.
     */
    private function __construct(
        public string $outcome,
        public ?string $checkoutUrl = null,
        public ?CarbonInterface $effectiveAt = null,
    ) {}

    /**
     * The new tier applies now.
     *
     * @return PlanChange
     */
    public static function changed(): self
    {
        return new self('changed');
    }

    /**
     * The account was already on that tier.
     *
     * @return PlanChange
     */
    public static function unchanged(): self
    {
        return new self('unchanged');
    }

    /**
     * The paid period runs out first; the change happens then.
     *
     * @param  CarbonInterface  $at
     * @return PlanChange
     */
    public static function scheduled(CarbonInterface $at): self
    {
        return new self('scheduled', effectiveAt: $at);
    }

    /**
     * The first paid plan: send the person to Stripe Checkout; the webhook applies it.
     *
     * @param  string  $url
     * @return PlanChange
     */
    public static function checkout(string $url): self
    {
        return new self('checkout', $url);
    }
}
