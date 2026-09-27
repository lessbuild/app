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
    /** Stable identifier stored in the database, e.g. `deploy`. */
    public function key(): string;

    public function name(): string;

    /** One sentence for enable pages and the overview's service cards. */
    public function tagline(): string;

    /** Icon name in the Signal icon sprite. */
    public function icon(): string;

    /** @return list<ServiceNavItem> the service's pages inside a project, first one is its landing page */
    public function navItems(string $projectId): array;

    /** @return list<ApiScope> */
    public function apiScopes(): array;

    /** Tiers, add-ons and meters this service sells. */
    public function billing(): ServiceBilling;
}
