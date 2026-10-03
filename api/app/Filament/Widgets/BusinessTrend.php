<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Services\Admin\BusinessAnalytics;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

/** One of the Business page's daily charts over the last 30 days: sign-ups, deploys or monitoring checks. */
final class BusinessTrend extends ChartWidget
{
    /**
     * Only shown where placed (the Business page), not on the dashboard.
     *
     * @var bool
     */
    protected static bool $isDiscovered = false;

    /**
     * Rendered with the page, since the numbers come from a cached snapshot.
     *
     * @var bool
     */
    protected static bool $isLazy = false;

    /**
     * Which series to draw: signups, deploys or checks.
     *
     * @var string
     */
    public string $metric = 'signups';

    /**
     * The chart's height.
     *
     * @var string|null
     */
    protected ?string $maxHeight = '220px';

    /**
     * Name the chart after its series.
     *
     * @return string
     */
    public function getHeading(): string
    {
        return match ($this->metric) {
            'deploys' => __('Deploys per day'),
            'checks' => __('Monitoring checks per day'),
            default => __('Sign-ups per day'),
        };
    }

    /**
     * Draw bars.
     *
     * @return string
     */
    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * Get the series from the cached business snapshot.
     *
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $trend = app(BusinessAnalytics::class)->snapshot()['trend'];
        $metric = in_array($this->metric, ['signups', 'deploys', 'checks'], true) ? $this->metric : 'signups';

        return [
            'datasets' => [['label' => $this->getHeading(), 'data' => array_map(fn (array $day): int => (int) $day[$metric], $trend)]],
            'labels' => array_map(fn (array $day): string => CarbonImmutable::parse((string) $day['date'])->format('j M'), $trend),
        ];
    }

    /**
     * Leave out the legend (the heading names the one series) and count in whole numbers.
     *
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return ['plugins' => ['legend' => ['display' => false]], 'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]]];
    }
}
