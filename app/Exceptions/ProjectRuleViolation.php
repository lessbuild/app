<?php

declare(strict_types=1);

namespace App\Exceptions;

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

    public static function invalidHostname(): self
    {
        return new self('hostname', __('Enter a public hostname such as shop.example.com.'));
    }

    public static function domainAlreadyAdded(): self
    {
        return new self('hostname', __('This project already has that domain.'));
    }

    public static function domainVerifiedElsewhere(): self
    {
        return new self('domain', __('Another project has already verified this domain. Remove it there first.'));
    }

    public static function environmentNotInProject(): self
    {
        return new self('environment_id', __('Choose one of this project’s environments.'));
    }
}
