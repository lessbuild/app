<?php

namespace App\Modules\Deployer\Providers;

use App\Core\Providers\ModuleServiceProvider;

final class DeployerServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return 'Modules/Deployer';
    }

    protected function moduleKey(): string
    {
        return 'deployer';
    }
}
