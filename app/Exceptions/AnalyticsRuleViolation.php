<?php

declare(strict_types=1);

namespace App\Exceptions;

/** Rendered as a validation error. */
final class AnalyticsRuleViolation extends RuleViolation
{
    /**
     * Build the violation for a site that lists a domain that isn't a public hostname (an IP, `localhost`, or
     * something with a path), which the tracker could never report from.
     *
     * @param  string  $domain
     * @return AnalyticsRuleViolation
     */
    public static function invalidDomain(string $domain): self
    {
        return new self('domains', __(':domain isn’t a public hostname.', ['domain' => $domain]));
    }

    /**
     * Build the violation for a new site beyond the plan's site limit.
     *
     * @param  string  $reason  the entitlement's explanation
     * @return AnalyticsRuleViolation
     */
    public static function siteLimitReached(string $reason): self
    {
        return new self('name', $reason);
    }

    /**
     * Build the violation for a site pointed at an environment from a different project.
     *
     * @return AnalyticsRuleViolation
     */
    public static function environmentNotInProject(): self
    {
        return new self('environment_id', __('Choose one of this project’s environments.'));
    }
}
