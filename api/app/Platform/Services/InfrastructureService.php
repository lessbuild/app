<?php

declare(strict_types=1);

namespace App\Platform\Services;

use App\Enums\ApiScope;
use App\Platform\Catalog\InfrastructureCatalog;
use App\Platform\Catalog\ServiceBilling;
use App\Platform\PlatformService;
use App\Platform\ServiceNavItem;

/** Servers on the account's cloud providers (ported from the Deployer module). Servers belong to the account; every project lists them. */
final class InfrastructureService implements PlatformService
{
    /**
     * Get the service's key, stored on projects and billing items as `infrastructure`.
     *
     * @return string
     */
    public function key(): string
    {
        return 'infrastructure';
    }

    /**
     * Get the service's name, shown as "Infrastructure".
     *
     * @return string
     */
    public function name(): string
    {
        return 'Infrastructure';
    }

    /**
     * Describe Infrastructure on the service cards.
     *
     * @return string
     */
    public function tagline(): string
    {
        return __('Servers, databases, domains and backups on the providers you choose.');
    }

    /**
     * Get the service's icon: a server.
     *
     * @return string
     */
    public function icon(): string
    {
        return 'server';
    }

    /**
     * Get the service's pages: servers (and imports), websites, load balancers, backups and costs.
     *
     * @param  string  $projectId
     * @return list<ServiceNavItem>
     */
    public function navItems(string $projectId): array
    {
        return [
            new ServiceNavItem(__('Servers'), route('infrastructure.servers', $projectId), 'infrastructure.servers*|infrastructure.imports*'),
            new ServiceNavItem(__('Websites'), route('infrastructure.websites', $projectId), 'infrastructure.websites*'),
            new ServiceNavItem(__('Load balancers'), route('infrastructure.load-balancers', $projectId), 'infrastructure.load-balancers*'),
            new ServiceNavItem(__('Backups'), route('infrastructure.backups', $projectId), 'infrastructure.backups*'),
            new ServiceNavItem(__('Storage'), route('infrastructure.storage', $projectId), 'infrastructure.storage*'),
            new ServiceNavItem(__('Costs'), route('infrastructure.costs', $projectId), 'infrastructure.costs*'),
        ];
    }

    /**
     * Get the service's API scopes: Infrastructure read and write.
     *
     * @return list<ApiScope>
     */
    public function apiScopes(): array
    {
        return [ApiScope::InfrastructureRead, ApiScope::InfrastructureWrite];
    }

    /**
     * Get the service's catalogue: a single included tier; server limits come from the Deploy plan for now.
     *
     * @return ServiceBilling
     */
    public function billing(): ServiceBilling
    {
        return InfrastructureCatalog::billing();
    }
}
