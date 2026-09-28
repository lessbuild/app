<?php

declare(strict_types=1);

namespace App\Exceptions;

/** Rendered as a validation error. */
final class BillingRuleViolation extends RuleViolation
{
    /**
     * The requested tier isn't in the service's billing catalogue.
     *
     * @return BillingRuleViolation
     */
    public static function unknown(): self
    {
        return new self('tier', __('That plan doesn’t exist.'));
    }

    /**
     * The tier exists in the catalogue but has no price yet, so it can't be chosen.
     *
     * @return BillingRuleViolation
     */
    public static function notOnSale(): self
    {
        return new self('tier', __('That plan isn’t on sale yet.'));
    }

    /**
     * No payment provider is configured in this environment, so only free tiers can be chosen.
     *
     * @return BillingRuleViolation
     */
    public static function unavailable(): self
    {
        return new self('tier', __('Payments aren’t set up in this environment, so paid plans can’t be bought.'));
    }
}
