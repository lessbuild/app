<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\Admin\SystemHealth;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** The platform's health: every check, the queues, and how long each kind of data is kept. Nothing shows secrets. */
final class Health extends Page
{
    /**
     * The page's Blade view.
     *
     * @var string
     */
    protected string $view = 'filament.pages.health';

    /**
     * The sidebar icon.
     *
     * @var string|BackedEnum|null
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    /**
     * The sidebar group.
     *
     * @var string|UnitEnum|null
     */
    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    /**
     * The position in the group.
     *
     * @var int|null
     */
    protected static ?int $navigationSort = 10;

    /**
     * Say what the page is for.
     *
     * @return string
     */
    public function getSubheading(): string
    {
        return __('Checked just now. Nothing here shows secrets, so the report is safe to share.');
    }

    /**
     * Get the header's button: the report as JSON.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [Action::make('report')->label(__('Download report'))->icon(Heroicon::OutlinedArrowDownTray)->color('gray')->url(route('admin.health.report'))];
    }

    /**
     * Give the view the checks, queues and retention rules.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $health = app(SystemHealth::class);

        return ['checks' => $health->checks(), 'queues' => $health->queues(), 'retention' => $health->retention()];
    }
}
