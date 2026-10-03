<?php

declare(strict_types=1);

namespace App\Platform\Services;

use App\Enums\ApiScope;
use App\Platform\Catalog\AuditCatalog;
use App\Platform\Catalog\ServiceBilling;
use App\Platform\PlatformService;
use App\Platform\ServiceNavItem;

/** Audit: a simulated visitor tries real tasks on a site and its competitors, and the report says what to improve. */
final class AuditService implements PlatformService
{
    /**
     * Get the service's key, stored on projects and billing items as `audit`.
     *
     * @return string
     */
    public function key(): string
    {
        return 'audit';
    }

    /**
     * Get the service's name, shown as "Audit".
     *
     * @return string
     */
    public function name(): string
    {
        return 'Audit';
    }

    /**
     * Describe Audit on the service cards.
     *
     * @return string
     */
    public function tagline(): string
    {
        return __('Watch a visitor use your site and your competitors’, and see what to improve.');
    }

    /**
     * Get the service's icon: a magnifying glass over a page.
     *
     * @return string
     */
    public function icon(): string
    {
        return 'search';
    }

    /**
     * Get the service's pages, served by the Next.js frontend: the audits and the latest report.
     *
     * @param  string  $projectId
     * @return list<ServiceNavItem>
     */
    public function navItems(string $projectId): array
    {
        return [
            new ServiceNavItem(__('Audits'), url("/projects/{$projectId}/audit"), 'audit.*'),
        ];
    }

    /**
     * Get the service's API scopes: Audit read and write.
     *
     * @return list<ApiScope>
     */
    public function apiScopes(): array
    {
        return [ApiScope::AuditRead, ApiScope::AuditWrite];
    }

    /**
     * Get the Audit catalogue.
     *
     * @return ServiceBilling
     */
    public function billing(): ServiceBilling
    {
        return AuditCatalog::billing();
    }
}
