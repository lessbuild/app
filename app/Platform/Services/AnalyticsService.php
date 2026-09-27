<?php

declare(strict_types=1);

namespace App\Platform\Services;

use App\Enums\ApiScope;
use App\Platform\Catalog\AnalyticsCatalog;
use App\Platform\Catalog\ServiceBilling;
use App\Platform\PlatformService;
use App\Platform\ServiceNavItem;

/** Privacy-friendly website analytics (ported from the standalone Analytics app). */
final class AnalyticsService implements PlatformService
{
    public function key(): string
    {
        return 'analytics';
    }

    public function name(): string
    {
        return 'Analytics';
    }

    public function tagline(): string
    {
        return __('Privacy-friendly traffic analytics, goals and reports.');
    }

    public function icon(): string
    {
        return 'view-grid';
    }

    public function navItems(string $projectId): array
    {
        return [
            new ServiceNavItem(__('Overview'), route('analytics.overview', $projectId), 'analytics.overview'),
            new ServiceNavItem(__('Sites'), route('analytics.sites', $projectId), 'analytics.sites*'),
        ];
    }

    public function apiScopes(): array
    {
        return [ApiScope::AnalyticsRead, ApiScope::AnalyticsWrite];
    }

    public function billing(): ServiceBilling
    {
        return AnalyticsCatalog::billing();
    }
}
