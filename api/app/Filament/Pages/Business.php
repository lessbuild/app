<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\Admin\BusinessAnalytics;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** How the business is doing: estimated revenue, accounts and people, daily trends, the sign-up funnel and tiers. */
final class Business extends Page
{
    /**
     * The page's Blade view.
     *
     * @var string
     */
    protected string $view = 'filament.pages.business';

    /**
     * The sidebar icon.
     *
     * @var string|BackedEnum|null
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    /**
     * The sidebar group.
     *
     * @var string|UnitEnum|null
     */
    protected static string|UnitEnum|null $navigationGroup = 'Growth';

    /**
     * The position in the group.
     *
     * @var int|null
     */
    protected static ?int $navigationSort = 10;

    /**
     * Say how the numbers are worked out.
     *
     * @return string
     */
    public function getSubheading(): string
    {
        return __('Worked out at :time and cached for five minutes. Revenue is estimated from the chosen tiers and add-ons at catalogue prices; metered usage isn’t included.', [
            'time' => CarbonImmutable::parse(app(BusinessAnalytics::class)->snapshot()['generated_at'])->toDayDateTimeString(),
        ]);
    }

    /**
     * Give the view the cached business snapshot.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return app(BusinessAnalytics::class)->snapshot();
    }
}
