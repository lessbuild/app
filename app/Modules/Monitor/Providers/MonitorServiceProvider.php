<?php

namespace App\Modules\Monitor\Providers;

use App\Core\Providers\ModuleServiceProvider;

final class MonitorServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return 'Modules/Monitor';
    }

    protected function moduleKey(): string
    {
        return 'monitor';
    }
}
