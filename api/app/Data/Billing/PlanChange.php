<?php

declare(strict_types=1);

namespace App\Data\Billing;

use Carbon\CarbonInterface;

final readonly class PlanChange
{
    /**
     * Create a new PlanChange instance.
     *
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
     * Build the outcome for a tier change that applies now.
     *
     * @return PlanChange
     */
    public static function changed(): self
    {
        return new self('changed');
    }

    /**
     * Build the outcome for a request that changed nothing, because the account was already on that tier.
     *
     * @return PlanChange
     */
    public static function unchanged(): self
    {
        return new self('unchanged');
    }

    /**
     * Build the outcome for a change that waits until the paid period runs out.
     *
     * @param  CarbonInterface  $at
     * @return PlanChange
     */
    public static function scheduled(CarbonInterface $at): self
    {
        return new self('scheduled', effectiveAt: $at);
    }

    /**
     * Build the outcome for a first paid plan: send the person to Stripe Checkout; the webhook applies it.
     *
     * @param  string  $url
     * @return PlanChange
     */
    public static function checkout(string $url): self
    {
        return new self('checkout', $url);
    }
}
