<?php

declare(strict_types=1);

namespace App\Exceptions;

/** A request that is authorised but breaks an account invariant; rendered as a validation error. */
final class AccountRuleViolation extends RuleViolation
{
    /**
     * Build the violation for a change that would leave the account without an owner, whether by demotion or removal.
     *
     * @return AccountRuleViolation
     */
    public static function lastOwner(): self
    {
        return new self('role', __('An account needs at least one owner. Make someone else an owner first.'));
    }

    /**
     * Build the violation for an actor whose role doesn't outrank the member's role (or the role being given), so they
     * can't change, remove or restrict that member.
     *
     * @return AccountRuleViolation
     */
    public static function cannotAssign(): self
    {
        return new self('role', __('You can’t assign that role.'));
    }

    /**
     * Build the violation for inviting an email that already belongs to a member of the account.
     *
     * @return AccountRuleViolation
     */
    public static function alreadyMember(): self
    {
        return new self('email', __('That person is already a member of this account.'));
    }

    /**
     * Build the violation for an invitation that was revoked, has expired or was already accepted.
     *
     * @return AccountRuleViolation
     */
    public static function invitationUnavailable(): self
    {
        return new self('invitation', __('This invitation is no longer valid. Ask for a new one.'));
    }

    /**
     * Build the violation for accepting an invitation sent to a different email address.
     *
     * @return AccountRuleViolation
     */
    public static function invitationForSomeoneElse(): self
    {
        return new self('invitation', __('This invitation was sent to a different email address.'));
    }
}
