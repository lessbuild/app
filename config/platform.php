<?php

use App\Modules\Deployer\Console\DeployerSchedule;

return [
    /*
    | Hostnames are deliberately optional during the transition. Existing
    | routes keep their current origin until each product's production host
    | has been confirmed and its module is ready to accept traffic.
    */
    'dashboard_host' => env('PLATFORM_DASHBOARD_HOST'),
    'dashboard_url' => env('PLATFORM_DASHBOARD_URL', filled(env('PLATFORM_DASHBOARD_HOST')) ? 'https://'.env('PLATFORM_DASHBOARD_HOST') : null),
    'auth_host' => env('PLATFORM_AUTH_HOST'),
    'auth_url' => env('PLATFORM_AUTH_URL', filled(env('PLATFORM_AUTH_HOST')) ? 'https://'.env('PLATFORM_AUTH_HOST') : null),

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

    'schedulers' => [
        'deployer' => DeployerSchedule::class,
    ],

    // Module migrations run only against their named database. The old
    // database/migrations path remains a compatibility symlink for Deployer.
    'migrations' => [
        'core' => [
            'connection' => 'core',
            'path' => app_path('Core/Database/Migrations'),
        ],
        'deployer' => [
            'connection' => 'deployer',
            'path' => app_path('Modules/Deployer/Database/Migrations'),
        ],
        'monitor' => [
            'connection' => 'monitor',
            'path' => app_path('Modules/Monitor/Database/Migrations'),
        ],
        'analytics' => [
            'connection' => 'analytics',
            'path' => app_path('Modules/Analytics/Database/Migrations'),
        ],
    ],
];
