<?php

declare(strict_types=1);

namespace App\Exceptions;

/** A request that is authorised but breaks a project invariant; rendered as a validation error. */
final class ProjectRuleViolation extends RuleViolation
{
    /**
     * Build the violation for a service key that doesn't match any registered platform service.
     *
     * @param  string  $service
     * @return ProjectRuleViolation
     */
    public static function unknownService(string $service): self
    {
        return new self('service', __('There is no service called :service.', ['service' => $service]));
    }

    /**
     * Build the violation for creating a second production environment or deleting the one every project has.
     *
     * @return ProjectRuleViolation
     */
    public static function productionIsFixed(): self
    {
        return new self('kind', __('A project has exactly one production environment, created with the project.'));
    }

    /**
     * Build the violation for an environment name the project already uses.
     *
     * @return ProjectRuleViolation
     */
    public static function environmentNameTaken(): self
    {
        return new self('name', __('This project already has an environment with that name.'));
    }

    /**
     * Build the violation for a domain that isn't a public hostname.
     *
     * @return ProjectRuleViolation
     */
    public static function invalidHostname(): self
    {
        return new self('hostname', __('Enter a public hostname such as shop.example.com.'));
    }

    /**
     * Build the violation for a domain the project already lists.
     *
     * @return ProjectRuleViolation
     */
    public static function domainAlreadyAdded(): self
    {
        return new self('hostname', __('This project already has that domain.'));
    }

    /**
     * Build the violation for a domain another project proved ownership of first; a domain can only be verified in one
     * project at a time.
     *
     * @return ProjectRuleViolation
     */
    public static function domainVerifiedElsewhere(): self
    {
        return new self('domain', __('Another project has already verified this domain. Remove it there first.'));
    }

    /**
     * Build the violation for a domain pointed at an environment from a different project.
     *
     * @return ProjectRuleViolation
     */
    public static function environmentNotInProject(): self
    {
        return new self('environment_id', __('Choose one of this project’s environments.'));
    }
}
