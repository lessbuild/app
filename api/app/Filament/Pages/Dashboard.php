<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\AdminTrail;
use App\Filament\Widgets\PlatformOverview;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\WidgetConfiguration;

/** The admin panel's home: what needs attention, and the admin trail. */
final class Dashboard extends BaseDashboard
{
    /**
     * Get the dashboard's widgets (the Business page has its own charts).
     *
     * @return array<class-string<\Filament\Widgets\Widget>|WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        return [PlatformOverview::class, AdminTrail::class];
    }
}
