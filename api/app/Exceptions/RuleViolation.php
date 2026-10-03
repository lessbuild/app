<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/**
 * A request that is authorised but would break one of the platform's rules (the last owner leaving, a duplicate
 * domain, a plan that isn't on sale). bootstrap/app.php renders every subclass as a validation error on `$field`,
 * so forms show the message next to the input that caused it.
 */
abstract class RuleViolation extends DomainException
{
    /**
     * Create a new RuleViolation instance.
     *
     * Subclasses build these through named constructors (`AccountRuleViolation::lastOwner()`), so each rule's message
     * is written once.
     *
     * @param  string  $field  The form field the message belongs to.
     * @param  string  $message  The explanation shown to the person, already translated.
     */
    final public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
