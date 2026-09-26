<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Exceptions;

use DomainException;

/** A request that is authorised but breaks an account invariant; rendered as a validation error. */
final class AccountRuleViolation extends DomainException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }

    public static function lastOwner(): self
    {
        return new self('role', __('An account needs at least one owner. Make someone else an owner first.'));
    }

    public static function cannotAssign(): self
    {
        return new self('role', __('You can’t assign that role.'));
    }

    public static function alreadyMember(): self
    {
        return new self('email', __('That person is already a member of this account.'));
    }

    public static function invitationUnavailable(): self
    {
        return new self('invitation', __('This invitation is no longer valid. Ask for a new one.'));
    }

    public static function invitationForSomeoneElse(): self
    {
        return new self('invitation', __('This invitation was sent to a different email address.'));
    }
}
