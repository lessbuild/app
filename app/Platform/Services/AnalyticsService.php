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
    /**
     * Stored on projects and billing items as `analytics`.
     *
     * @return string
     */
    public function key(): string
    {
        return 'analytics';
    }

    /**
     * Shown as "Analytics".
     *
     * @return string
     */
    public function name(): string
    {
        return 'Analytics';
    }

    /**
     * Describes Analytics on the service cards.
     *
     * @return string
     */
    public function tagline(): string
    {
        return __('Privacy-friendly traffic analytics, goals and reports.');
    }

    /**
     * A grid, standing for dashboards of numbers.
     *
     * @return string
     */
    public function icon(): string
    {
        return 'view-grid';
    }

    /**
     * The traffic overview, goals and the sites that send pageviews.
     *
     * @param  string  $projectId
     * @return list<ServiceNavItem>
     */
    public function navItems(string $projectId): array
    {
        return [
            new ServiceNavItem(__('Overview'), route('analytics.overview', $projectId), 'analytics.overview'),
            new ServiceNavItem(__('Goals'), route('analytics.goals', $projectId), 'analytics.goals*'),
            new ServiceNavItem(__('Sites'), route('analytics.sites', $projectId), 'analytics.sites*'),
        ];
    }

    /**
     * Analytics read and write.
     *
     * @return list<ApiScope>
     */
    public function apiScopes(): array
    {
        return [ApiScope::AnalyticsRead, ApiScope::AnalyticsWrite];
    }

    /**
     * The Analytics catalogue: a free tier until paid plans are priced.
     *
     * @return ServiceBilling
     */
    public function billing(): ServiceBilling
    {
        return AnalyticsCatalog::billing();
    }
}
