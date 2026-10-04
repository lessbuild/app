<?php

declare(strict_types=1);

namespace App\Data\Monitoring;

use App\Models\Dashboard;
use App\Queries\Telemetry\TelemetrySummaryQuery;

final readonly class DashboardForm
{
    /**
     * Create a new DashboardForm instance.
     *
     * The dashboard form's choices and, when editing, the dashboard's settings.
     *
     * @param  list<array{value: string, label: string}>  $widgets  The widgets a dashboard can show, in order.
     * @param  list<array{value: string, label: string}>  $ranges  The telemetry ranges it can cover.
     * @param  array{name: string, description: string|null, range: string, widgets: list<string>}|null  $dashboard
     */
    public function __construct(
        public array $widgets,
        public array $ranges,
        public ?array $dashboard,
    ) {}

    /**
     * Describe the form for a new dashboard, or for editing one.
     *
     * @param  Dashboard|null  $dashboard
     * @return self
     */
    public static function for(?Dashboard $dashboard): self
    {
        return new self(
            widgets: array_map(fn (string $type, string $label): array => ['value' => $type, 'label' => __($label)], array_keys(Dashboard::WIDGETS), Dashboard::WIDGETS),
            ranges: array_map(fn (string $range, string $label): array => ['value' => $range, 'label' => __($label)], array_keys(TelemetrySummaryQuery::RANGES), TelemetrySummaryQuery::RANGES),
            dashboard: $dashboard === null ? null : [
                'name' => $dashboard->name,
                'description' => $dashboard->description,
                'range' => $dashboard->range,
                'widgets' => array_values(array_map('strval', $dashboard->widgets()->pluck('type')->all())),
            ],
        );
    }
}
