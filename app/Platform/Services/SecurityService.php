<?php

declare(strict_types=1);

namespace App\Platform\Services;

use App\Enums\ApiScope;
use App\Platform\Catalog\SecurityCatalog;
use App\Platform\Catalog\ServiceBilling;
use App\Platform\PlatformService;
use App\Platform\ServiceNavItem;

/** Security for what a project runs: vulnerable packages, leaked secrets, server hardening, domains and access. */
final class SecurityService implements PlatformService
{
    /**
     * Get the service's key, stored on projects and billing items as `security`.
     *
     * @return string
     */
    public function key(): string
    {
        return 'security';
    }

    /**
     * Get the service's name, shown as "Security".
     *
     * @return string
     */
    public function name(): string
    {
        return 'Security';
    }

    /**
     * Describe Security on the service cards.
     *
     * @return string
     */
    public function tagline(): string
    {
        return __('Vulnerable packages, leaked secrets, server hardening and attacks, in one place.');
    }

    /**
     * Get the service's icon: a shield with a tick.
     *
     * @return string
     */
    public function icon(): string
    {
        return 'shield-check';
    }

    /**
     * Get the service's pages: the overview, the findings, the servers, the Cloudflare firewall and blocked attacks.
     *
     * @param  string  $projectId
     * @return list<ServiceNavItem>
     */
    public function navItems(string $projectId): array
    {
        return [
            new ServiceNavItem(__('Overview'), route('security.overview', $projectId), 'security.overview'),
            new ServiceNavItem(__('Findings'), route('security.findings', $projectId), 'security.findings*'),
            new ServiceNavItem(__('Servers'), route('security.servers', $projectId), 'security.servers*'),
            new ServiceNavItem(__('Firewall'), route('security.firewall', $projectId), 'security.firewall*'),
            new ServiceNavItem(__('Attacks'), route('security.attacks', $projectId), 'security.attacks*'),
        ];
    }

    /**
     * Get the service's API scopes: Security read and write.
     *
     * @return list<ApiScope>
     */
    public function apiScopes(): array
    {
        return [ApiScope::SecurityRead, ApiScope::SecurityWrite];
    }

    /**
     * Get the Security catalogue.
     *
     * @return ServiceBilling
     */
    public function billing(): ServiceBilling
    {
        return SecurityCatalog::billing();
    }
}
