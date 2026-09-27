<?php

declare(strict_types=1);

return [
    'monitors' => [
        // Shown on each check as the place it ran from.
        'location' => env('MONITOR_LOCATION', 'This server'),
    ],

    'alerts' => [
        // Mailer for alert emails; null uses the default mailer.
        'mailer' => env('ALERT_MAILER'),
    ],

    'telemetry' => [
        'max_json_depth' => 32,
        'sensitive_keys' => [
            '*password*', '*passwd*', 'pwd', '*secret*', '*token', '*apikey*',
            '*authorization*', '*cookie*', '*privatekey*', '*sessionid',
            '*connectionstring*', '*cardnumber', 'cvv', 'ssn', '*email', '*emailaddress',
        ],
        'redacted_paths' => [],
    ],
];
