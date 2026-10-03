<?php

declare(strict_types=1);

return [
    'event_retention_days' => (int) env('ANALYTICS_EVENT_RETENTION_DAYS', 90),
    'aggregate_retention_months' => (int) env('ANALYTICS_AGGREGATE_RETENTION_MONTHS', 13),
    'export_retention_hours' => (int) env('ANALYTICS_EXPORT_RETENTION_HOURS', 24),
    // Keys the daily visitor hash. Keep it identical to the old Analytics app's key when migrating, or visitor counts restart.
    'visitor_key' => env('ANALYTICS_VISITOR_KEY') ?: env('APP_KEY'),
    // DB-IP's free IP-to-country database (CC BY 4.0), refreshed monthly by analytics:update-geoip. Set the edition to
    // "city" for regions and cities too; that database is about 130 MB instead of 8 MB.
    'geoip_edition' => env('ANALYTICS_GEOIP_EDITION', 'country') === 'city' ? 'city' : 'country',
    'geoip_database' => env('ANALYTICS_GEOIP_DATABASE', storage_path('app/geoip/dbip-'.(env('ANALYTICS_GEOIP_EDITION') === 'city' ? 'city' : 'country').'-lite.mmdb')),
    'geoip_url' => env('ANALYTICS_GEOIP_URL', 'https://download.db-ip.com/free/dbip-'.(env('ANALYTICS_GEOIP_EDITION') === 'city' ? 'city' : 'country').'-lite-{month}.mmdb.gz'),
    'collect_rate_per_minute' => (int) env('ANALYTICS_COLLECT_RATE_PER_MINUTE', 120),
];
