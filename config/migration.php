<?php

return [
    'source_app_keys' => [
        'deployer' => env('MIGRATION_DEPLOYER_APP_KEY'),
        'monitor' => env('MIGRATION_MONITOR_APP_KEY'),
        'analytics' => env('MIGRATION_ANALYTICS_APP_KEY'),
    ],
    'source_ciphers' => [
        'deployer' => env('MIGRATION_DEPLOYER_CIPHER', 'AES-256-CBC'),
        'monitor' => env('MIGRATION_MONITOR_CIPHER', 'AES-256-CBC'),
        'analytics' => env('MIGRATION_ANALYTICS_CIPHER', 'AES-256-CBC'),
    ],
];
