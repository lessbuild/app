<?php

declare(strict_types=1);

// One-click project setups: the services a project gets, and how its website builds, runs and is checked.
return [
    'laravel' => [
        'name' => 'Laravel',
        'description' => 'A Laravel app on PHP-FPM: Composer and npm installs, a Vite build, migrations before each release goes live, and uptime and analytics from day one.',
        'services' => ['deploy', 'infrastructure', 'monitoring', 'analytics'],
        'runtime' => ['type' => 'php', 'build_command' => 'npm run build --if-present', 'start_command' => null, 'container_port' => null],
        'build_commands' => "php artisan migrate --force\nphp artisan optimize",
        'post_deployment_commands' => 'php artisan queue:restart',
        'health_check_path' => '/up',
        'env' => "APP_ENV=production\nAPP_DEBUG=false\nLOG_CHANNEL=stack\n",
        'analytics' => true,
    ],
    'nextjs' => [
        'name' => 'Next.js',
        'description' => 'A Next.js app on Node.js behind Caddy: npm install, next build, and next start on port 3000, with uptime and analytics.',
        'services' => ['deploy', 'infrastructure', 'monitoring', 'analytics'],
        'runtime' => ['type' => 'node', 'build_command' => 'npm run build', 'start_command' => 'npm run start -- --port 3000', 'container_port' => 3000],
        'build_commands' => null,
        'post_deployment_commands' => null,
        'health_check_path' => '/',
        'env' => "NODE_ENV=production\n",
        'analytics' => true,
    ],
    'wordpress' => [
        'name' => 'WordPress',
        'description' => 'A WordPress site kept in Git (for example Bedrock): Composer install on PHP-FPM with its MySQL database, and uptime and analytics.',
        'services' => ['deploy', 'infrastructure', 'monitoring', 'analytics'],
        'runtime' => ['type' => 'php', 'build_command' => null, 'start_command' => null, 'container_port' => null],
        'build_commands' => null,
        'post_deployment_commands' => null,
        'health_check_path' => '/',
        'env' => "WP_ENV=production\n",
        'analytics' => true,
    ],
    'static' => [
        'name' => 'Static site',
        'description' => 'Plain files (or a static site generator writing to public/) served by Caddy, with uptime and analytics.',
        'services' => ['deploy', 'infrastructure', 'monitoring', 'analytics'],
        'runtime' => ['type' => 'php', 'build_command' => 'npm run build --if-present', 'start_command' => null, 'container_port' => null],
        'build_commands' => null,
        'post_deployment_commands' => null,
        'health_check_path' => '/',
        'env' => '',
        'analytics' => true,
    ],
];
