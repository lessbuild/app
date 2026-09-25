<?php

return [
    'plan_authority' => env('ANALYTICS_PLAN_AUTHORITY', 'legacy'),
    'horizon_enabled' => (bool) env('ANALYTICS_HORIZON_ENABLED', false),
    'platform_status' => [
        'worker_stale_after_seconds' => (int) env('ANALYTICS_WORKER_STALE_AFTER_SECONDS', 180),
    ],
    'event_retention_days' => (int) env('ANALYTICS_EVENT_RETENTION_DAYS', 90),
    'aggregate_retention_months' => (int) env('ANALYTICS_AGGREGATE_RETENTION_MONTHS', 13),
    'export_retention_hours' => (int) env('ANALYTICS_EXPORT_RETENTION_HOURS', 24),
    'invitation_expiry_days' => (int) env('ANALYTICS_INVITATION_EXPIRY_DAYS', 7),
    'visitor_key' => env('ANALYTICS_VISITOR_KEY') ?: env('APP_KEY'),
    'collect_rate_per_minute' => (int) env('ANALYTICS_COLLECT_RATE_PER_MINUTE', 120),
    'allowed_hosts' => array_values(array_filter(array_map(
        static fn (string $host): string => trim($host),
        explode(',', (string) env('ANALYTICS_ALLOWED_HOSTS', 'analytics.buildpusher.com,localhost,127.0.0.1')),
    ), static fn (string $host): bool => $host !== '')),
];
