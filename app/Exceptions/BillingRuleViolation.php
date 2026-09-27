<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/** Rendered as a validation error. */
final class BillingRuleViolation extends DomainException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }

    public static function unknown(): self
    {
        return new self('tier', __('That plan doesn’t exist.'));
    }

    public static function notOnSale(): self
    {
        return new self('tier', __('That plan isn’t on sale yet.'));
    }

    public static function unavailable(): self
    {
        return new self('tier', __('Payments aren’t set up in this environment, so paid plans can’t be bought.'));
    }
}
