<?php

declare(strict_types=1);

namespace App\Exceptions;

/** A request that is authorised but breaks a project invariant; rendered as a validation error. */
final class ProjectRuleViolation extends RuleViolation
{
    /**
     * The service key doesn't match any registered platform service.
     */
    public static function unknownService(string $service): self
    {
        return new self('service', __('There is no service called :service.', ['service' => $service]));
    }

    /**
     * Someone tried to create a second production environment or delete the one every project has.
     */
    public static function productionIsFixed(): self
    {
        return new self('kind', __('A project has exactly one production environment, created with the project.'));
    }

    /**
     * Environment names are unique within a project.
     */
    public static function environmentNameTaken(): self
    {
        return new self('name', __('This project already has an environment with that name.'));
    }

    /**
     * A domain being added isn't a public hostname.
     */
    public static function invalidHostname(): self
    {
        return new self('hostname', __('Enter a public hostname such as shop.example.com.'));
    }

    /**
     * The project already lists this domain.
     */
    public static function domainAlreadyAdded(): self
    {
        return new self('hostname', __('This project already has that domain.'));
    }

    /**
     * Another project proved ownership of this domain first; a domain can only be verified in one project at a time.
     */
    public static function domainVerifiedElsewhere(): self
    {
        return new self('domain', __('Another project has already verified this domain. Remove it there first.'));
    }

    /**
     * A domain was pointed at an environment from a different project.
     */
    public static function environmentNotInProject(): self
    {
        return new self('environment_id', __('Choose one of this project’s environments.'));
    }
}
