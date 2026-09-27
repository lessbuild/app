<?php

declare(strict_types=1);

namespace App\Platform\Services;

use App\Enums\ApiScope;
use App\Platform\Catalog\ServiceBilling;
use App\Platform\PlatformService;
use App\Platform\ServiceNavItem;

/**
 * A registered service whose features haven't been built yet (Phase 4). It can be enabled on a
 * project and shows a single landing page, so the shell, onboarding and access rules work end to end.
 */
final readonly class PlaceholderService implements PlatformService
{
    /** @param list<ApiScope> $apiScopes */
    public function __construct(
        private string $key,
        private string $name,
        private string $tagline,
        private string $icon,
        private array $apiScopes,
        private ServiceBilling $billing,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function tagline(): string
    {
        return $this->tagline;
    }

    public function icon(): string
    {
        return $this->icon;
    }

    public function navItems(string $projectId): array
    {
        return [new ServiceNavItem(__('Overview'), route('projects.services.show', [$projectId, $this->key]), 'projects.services.show')];
    }

    public function apiScopes(): array
    {
        return $this->apiScopes;
    }

    public function billing(): ServiceBilling
    {
        return $this->billing;
    }
}
