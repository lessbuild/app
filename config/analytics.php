<?php

declare(strict_types=1);

return [
    'event_retention_days' => (int) env('ANALYTICS_EVENT_RETENTION_DAYS', 90),
    'aggregate_retention_months' => (int) env('ANALYTICS_AGGREGATE_RETENTION_MONTHS', 13),
    'export_retention_hours' => (int) env('ANALYTICS_EXPORT_RETENTION_HOURS', 24),
    // Keys the daily visitor hash. Keep it identical to the old Analytics app's key when migrating, or visitor counts restart.
    'visitor_key' => env('ANALYTICS_VISITOR_KEY') ?: env('APP_KEY'),
    'collect_rate_per_minute' => (int) env('ANALYTICS_COLLECT_RATE_PER_MINUTE', 120),
];
