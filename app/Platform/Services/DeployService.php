<?php

declare(strict_types=1);

namespace App\Platform\Services;

use App\Enums\ApiScope;
use App\Platform\Catalog\DeployCatalog;
use App\Platform\Catalog\ServiceBilling;
use App\Platform\PlatformService;
use App\Platform\ServiceNavItem;

/** Builds and releases from Git onto Infrastructure's websites (ported from the Deployer module). */
final class DeployService implements PlatformService
{
    /**
     * Stored on projects and billing items as `deploy`.
     */
    public function key(): string
    {
        return 'deploy';
    }

    /**
     * Shown as "Deploy".
     */
    public function name(): string
    {
        return 'Deploy';
    }

    /**
     * Describes Deploy on the service cards.
     */
    public function tagline(): string
    {
        return __('Build and release your apps from Git, with previews and rollbacks.');
    }

    /**
     * An upload cloud, standing for releases.
     */
    public function icon(): string
    {
        return 'cloud-upload';
    }

    /**
     * Repositories (with their builds), environment deploy settings, and configuration documents.
     */
    public function navItems(string $projectId): array
    {
        return [
            new ServiceNavItem(__('Repositories'), route('deploy.repositories', $projectId), 'deploy.repositories*|deploy.builds*'),
            new ServiceNavItem(__('Environments'), route('deploy.environments', $projectId), 'deploy.environments*'),
            new ServiceNavItem(__('Configuration'), route('deploy.configuration', $projectId), 'deploy.configuration*'),
        ];
    }

    /**
     * Deploy read and write, which the Deployer API v1 endpoints check.
     */
    public function apiScopes(): array
    {
        return [ApiScope::DeployRead, ApiScope::DeployWrite];
    }

    /**
     * Deployer's tiers, carried over unchanged.
     */
    public function billing(): ServiceBilling
    {
        return DeployCatalog::billing();
    }
}
