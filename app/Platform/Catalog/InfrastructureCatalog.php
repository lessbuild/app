<?php

declare(strict_types=1);

namespace App\Platform\Catalog;

/** Servers have always been part of Deployer's plans; Deploy's tier sets the server limit until Infrastructure is priced on its own. */
final class InfrastructureCatalog
{
    public static function billing(): ServiceBilling
    {
        return new ServiceBilling([
            new Tier('included', __('Included'), 0, __('Server limits come from your Deploy plan.'), [__('Servers, websites, databases and backups'), __('Server count set by your Deploy plan')]),
        ]);
    }
}
