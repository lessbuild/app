<?php

declare(strict_types=1);

namespace App\Platform;

use App\Enums\ApiScope;
use App\Platform\Catalog\ServiceBilling;

/**
 * A product on the platform (Deploy, Monitoring…). The shell, onboarding and later billing read services
 * from the ServiceRegistry, so adding one needs no changes anywhere else.
 */
interface PlatformService
{
    /**
     * Get the service's stable identifier, stored in the database, e.g. `deploy`.
     *
     * @return string
     */
    public function key(): string;

    /**
     * Get the service's product name, as the sidebar, billing and enable pages show it.
     *
     * @return string
     */
    public function name(): string;

    /**
     * Get one sentence for enable pages and the overview's service cards.
     *
     * @return string
     */
    public function tagline(): string;

    /**
     * Get the icon name in the Signal icon sprite.
     *
     * @return string
     */
    public function icon(): string;

    /**
     * Get the service's pages inside a project, in sidebar order. The first is the landing page the service card links
     * to.
     *
     * @param  string  $projectId
     * @return list<ServiceNavItem> the service's pages inside a project, first one is its landing page
     */
    public function navItems(string $projectId): array;

    /**
     * Get the API token scopes this service adds (its read and write scopes), offered on the token form.
     *
     * @return list<ApiScope>
     */
    public function apiScopes(): array;

    /**
     * Get the tiers, add-ons and meters this service sells.
     *
     * @return ServiceBilling
     */
    public function billing(): ServiceBilling;
}
