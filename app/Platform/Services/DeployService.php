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
     * Get the service's key, stored on projects and billing items as `deploy`.
     *
     * @return string
     */
    public function key(): string
    {
        return 'deploy';
    }

    /**
     * Get the service's name, shown as "Deploy".
     *
     * @return string
     */
    public function name(): string
    {
        return 'Deploy';
    }

    /**
     * Describe Deploy on the service cards.
     *
     * @return string
     */
    public function tagline(): string
    {
        return __('Build and release your apps from Git, with previews and rollbacks.');
    }

    /**
     * Get the service's icon: an upload cloud, standing for releases.
     *
     * @return string
     */
    public function icon(): string
    {
        return 'cloud-upload';
    }

    /**
     * Get the service's pages: repositories (with their builds), environment deploy settings, and configuration
     * documents.
     *
     * @param  string  $projectId
     * @return list<ServiceNavItem>
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
     *
     * @return list<ApiScope>
     */
    public function apiScopes(): array
    {
        return [ApiScope::DeployRead, ApiScope::DeployWrite];
    }

    /**
     * Get Deployer's tiers, carried over unchanged.
     *
     * @return ServiceBilling
     */
    public function billing(): ServiceBilling
    {
        return DeployCatalog::billing();
    }
}
