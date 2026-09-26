<?php

declare(strict_types=1);

namespace App\Domain\Projects\Exceptions;

use DomainException;

/** A request that is authorised but breaks a project invariant; rendered as a validation error. */
final class ProjectRuleViolation extends DomainException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }

    public static function unknownService(string $service): self
    {
        return new self('service', __('There is no service called :service.', ['service' => $service]));
    }

    public static function productionIsFixed(): self
    {
        return new self('kind', __('A project has exactly one production environment, created with the project.'));
    }

    public static function environmentNameTaken(): self
    {
        return new self('name', __('This project already has an environment with that name.'));
    }
}
