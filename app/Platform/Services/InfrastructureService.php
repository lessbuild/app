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
    public function key(): string
    {
        return 'infrastructure';
    }

    public function name(): string
    {
        return 'Infrastructure';
    }

    public function tagline(): string
    {
        return __('Servers, databases, domains and backups on the providers you choose.');
    }

    public function icon(): string
    {
        return 'server';
    }

    public function navItems(string $projectId): array
    {
        return [
            new ServiceNavItem(__('Servers'), route('infrastructure.servers', $projectId), 'infrastructure.servers*|infrastructure.imports*'),
            new ServiceNavItem(__('Websites'), route('infrastructure.websites', $projectId), 'infrastructure.websites*'),
        ];
    }

    public function apiScopes(): array
    {
        return [ApiScope::InfrastructureRead, ApiScope::InfrastructureWrite];
    }

    public function billing(): ServiceBilling
    {
        return InfrastructureCatalog::billing();
    }
}
