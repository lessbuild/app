<?php

return [
    /*
    | Hostnames are deliberately optional during the transition. Existing
    | routes keep their current origin until each product's production host
    | has been confirmed and its module is ready to accept traffic.
    */
    'dashboard_host' => env('PLATFORM_DASHBOARD_HOST'),
    'auth_host' => env('PLATFORM_AUTH_HOST'),

    'products' => [
        'deployer' => [
            'label' => 'Deployer',
            'enabled' => true,
            'host' => env('DEPLOYER_HOST'),
            'url' => env('DEPLOYER_URL', filled(env('DEPLOYER_HOST')) ? 'https://'.env('DEPLOYER_HOST') : null),
            'database' => 'deployer',
        ],
        'monitor' => [
            'label' => 'Monitor',
            'enabled' => false,
            'host' => env('MONITOR_HOST'),
            'url' => env('MONITOR_URL', filled(env('MONITOR_HOST')) ? 'https://'.env('MONITOR_HOST') : null),
            'database' => 'monitor',
        ],
        'analytics' => [
            'label' => 'Analytics',
            'enabled' => false,
            'host' => env('ANALYTICS_HOST'),
            'url' => env('ANALYTICS_URL', filled(env('ANALYTICS_HOST')) ? 'https://'.env('ANALYTICS_HOST') : null),
            'database' => 'analytics',
        ],
    ],
];
