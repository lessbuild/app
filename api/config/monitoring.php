<?php

declare(strict_types=1);

return [
    'monitors' => [
        // Shown on each check as the place it ran from.
        'location' => env('MONITOR_LOCATION', 'This server'),
    ],

    'status_pages' => [
        // The hostname customers point their status page domains at (a CNAME). Defaults to the app's own host.
        'domain_target' => env('STATUS_PAGE_DOMAIN_TARGET', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost'),
    ],

    'alerts' => [
        // Mailer for alert emails; null uses the default mailer.
        'mailer' => env('ALERT_MAILER'),
    ],

    'telemetry' => [
        // Queue connection for processing accepted batches: the `telemetry` database queue, or `sync` in tests.
        'queue_connection' => env('TELEMETRY_QUEUE_CONNECTION', 'telemetry'),
        // An environment that has sent nothing for this long shows as stale.
        'collection_stale_after_minutes' => (int) env('TELEMETRY_STALE_AFTER_MINUTES', 60),
        // Public ingest limits, unchanged from the old Monitor app.
        'max_request_bytes' => 1_048_576,
        'max_decoded_bytes' => 4_194_304,
        'max_json_depth' => 32,
        'max_json_nodes' => 100_000,
        'max_events_per_batch' => 500,
        'max_attributes_per_record' => 128,
        'max_normalized_bytes' => 8_388_608,
        'sensitive_keys' => [
            '*password*', '*passwd*', 'pwd', '*secret*', '*token', '*apikey*',
            '*authorization*', '*cookie*', '*privatekey*', '*sessionid',
            '*connectionstring*', '*cardnumber', 'cvv', 'ssn', '*email', '*emailaddress',
        ],
        'redacted_paths' => [],
    ],
];
