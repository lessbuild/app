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
    public function key(): string
    {
        return 'deploy';
    }

    public function name(): string
    {
        return 'Deploy';
    }

    public function tagline(): string
    {
        return __('Build and release your apps from Git, with previews and rollbacks.');
    }

    public function icon(): string
    {
        return 'cloud-upload';
    }

    public function navItems(string $projectId): array
    {
        return [new ServiceNavItem(__('Repositories'), route('deploy.repositories', $projectId), 'deploy.*')];
    }

    public function apiScopes(): array
    {
        return [ApiScope::DeployRead, ApiScope::DeployWrite];
    }

    public function billing(): ServiceBilling
    {
        return DeployCatalog::billing();
    }
}
