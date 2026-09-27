<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/** Rendered as a validation error. */
final class AnalyticsRuleViolation extends DomainException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }

    public static function invalidDomain(string $domain): self
    {
        return new self('domains', __(':domain isn’t a public hostname.', ['domain' => $domain]));
    }

    public static function environmentNotInProject(): self
    {
        return new self('environment_id', __('Choose one of this project’s environments.'));
    }
}
