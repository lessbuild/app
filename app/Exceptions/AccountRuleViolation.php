<?php

declare(strict_types=1);

namespace App\Exceptions;

/** A request that is authorised but breaks an account invariant; rendered as a validation error. */
final class AccountRuleViolation extends RuleViolation
{
    /**
     * The change would leave the account without an owner, whether by demotion or removal.
     */
    public static function lastOwner(): self
    {
        return new self('role', __('An account needs at least one owner. Make someone else an owner first.'));
    }

    /**
     * The actor's role doesn't outrank the member's role (or the role being given), so they can't change, remove or
     * restrict that member.
     */
    public static function cannotAssign(): self
    {
        return new self('role', __('You can’t assign that role.'));
    }

    /**
     * The invited email already belongs to a member of the account.
     */
    public static function alreadyMember(): self
    {
        return new self('email', __('That person is already a member of this account.'));
    }

    /**
     * The invitation was revoked, has expired or was already accepted.
     */
    public static function invitationUnavailable(): self
    {
        return new self('invitation', __('This invitation is no longer valid. Ask for a new one.'));
    }

    /**
     * The signed-in person's email doesn't match the one the invitation was sent to.
     */
    public static function invitationForSomeoneElse(): self
    {
        return new self('invitation', __('This invitation was sent to a different email address.'));
    }
}
