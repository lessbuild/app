<?php

namespace App\Modules\Analytics\Providers;

use App\Core\Providers\ModuleServiceProvider;

final class AnalyticsServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return 'Modules/Analytics';
    }

    protected function moduleKey(): string
    {
        return 'analytics';
    }
}
