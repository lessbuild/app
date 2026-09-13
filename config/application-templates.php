<?php

$laravelServiceTemplate = [
    'version' => '1.0.0',
    'compatibility' => [
        'runtime' => 'php',
        'framework' => 'laravel',
        'php' => '8.2 - 8.5',
        'deployment' => 'BuildPusher managed website',
    ],
    'resources' => [
        [
            'name' => 'database',
            'type' => 'postgresql',
            'management' => 'managed',
            'credentials' => 'generated',
            'credential_keys' => ['DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'],
        ],
        [
            'name' => 'cache',
            'type' => 'valkey',
            'management' => 'managed',
            'credentials' => 'generated',
            'credential_keys' => ['REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD'],
        ],
    ],
    'persistent_data' => [
        ['name' => 'database', 'location' => '/var/lib/postgresql', 'retention' => 'preview_lifecycle'],
        ['name' => 'cache', 'location' => 'buildpusher-valkey-{environment_id}-cache-data', 'retention' => 'preview_lifecycle'],
    ],
    'readiness_checks' => [
        ['name' => 'web', 'kind' => 'http', 'path' => '/up'],
        ['name' => 'queue', 'kind' => 'process', 'process' => 'queue'],
        ['name' => 'scheduler', 'kind' => 'process', 'process' => 'scheduler'],
        ['name' => 'database', 'kind' => 'resource', 'resource' => 'database'],
        ['name' => 'cache', 'kind' => 'resource', 'resource' => 'cache'],
    ],
    'resource_limits' => [
        'processes' => 2,
        'resources' => 2,
        'replicas' => [
            'queue' => ['minimum' => 1, 'maximum' => 1],
            'scheduler' => ['minimum' => 1, 'maximum' => 1],
        ],
    ],
    'backup_restore' => [
        'database' => 'Use the managed PostgreSQL backup workflow and restore into an isolated target before replacing application data.',
        'cache' => 'Valkey is disposable preview state and is not included in application-data restore evidence.',
        'scope' => 'Application data recovery and BuildPusher control-plane recovery are separate workflows.',
    ],
    'upgrade' => [
        'policy' => 'Review template changes before applying them to an installed project.',
        'application' => 'Run framework and database migrations through the normal reviewed deployment workflow.',
        'resources' => 'Keep existing resource identities, persistent locations and credentials across revisions unless a separate migration is approved.',
    ],
    'failure_recovery' => [
        'initialization' => 'Retry the revision-bound initialization attempt after correcting the application or data issue.',
        'provisioning' => 'Retry failed resource provisioning through the preview lifecycle; ready resources are not downgraded by an application-only failure.',
        'cleanup' => 'Retry incomplete preview cleanup from its durable ownership record; shared resources remain protected.',
    ],
    'deletion' => [
        'policy' => 'Close a preview before deleting it and remove only resources explicitly owned by that preview.',
        'retention' => 'Successful cleanup retains no temporary preview data; failed cleanup remains visible and retryable.',
    ],
];

$nodeServiceTemplate = $laravelServiceTemplate;
$nodeServiceTemplate['compatibility'] = [
    'runtime' => 'node',
    'framework' => 'node',
    'node' => '20 - 24',
    'deployment' => 'BuildPusher managed website',
];
$nodeServiceTemplate['readiness_checks'] = [
    ['name' => 'web', 'kind' => 'http', 'path' => '/'],
    ['name' => 'database', 'kind' => 'resource', 'resource' => 'database'],
    ['name' => 'cache', 'kind' => 'resource', 'resource' => 'cache'],
];
$nodeServiceTemplate['resource_limits'] = [
    'processes' => 0,
    'resources' => 2,
    'replicas' => [],
];
$nodeServiceTemplate['upgrade'] = [
    'policy' => 'Review template changes before applying them to an installed project.',
    'application' => 'Run package installation, builds and schema changes through the normal reviewed deployment workflow.',
    'resources' => 'Keep existing resource identities, persistent locations and credentials across revisions unless a separate migration is approved.',
];
$nodeServiceTemplate['failure_recovery'] = [
    'provisioning' => 'Retry failed resource provisioning through the preview lifecycle; ready resources are not downgraded by an application-only failure.',
    'cleanup' => 'Retry incomplete preview cleanup from its durable ownership record; shared resources remain protected.',
];

return [
    'laravel' => [
        'name' => 'Laravel',
        'description' => 'Laravel web application with queue and scheduler workers.',
        'runtime_type' => 'php',
        'build_command' => null,
        'start_command' => null,
        'container_port' => null,
        'dockerfile_path' => null,
        'processes' => [
            ['name' => 'queue', 'type' => 'worker', 'command' => 'php artisan queue:work --sleep=3 --tries=3 --timeout=90'],
            ['name' => 'scheduler', 'type' => 'scheduler', 'command' => 'php artisan schedule:work'],
        ],
        'preview_resources' => [
            ['name' => 'database', 'type' => 'postgresql', 'is_managed' => true],
            ['name' => 'cache', 'type' => 'valkey', 'is_managed' => true],
        ],
        'preview_initialization' => ['command' => 'php artisan db:seed --force'],
        'service_template' => $laravelServiceTemplate,
    ],
    'laravel-inertia' => [
        'name' => 'Laravel + Inertia',
        'description' => 'Full-stack Laravel and Inertia application.',
        'runtime_type' => 'php',
        'build_command' => 'npm run build',
        'start_command' => null,
        'container_port' => null,
        'dockerfile_path' => null,
        'processes' => [
            ['name' => 'queue', 'type' => 'worker', 'command' => 'php artisan queue:work --sleep=3 --tries=3 --timeout=90'],
            ['name' => 'scheduler', 'type' => 'scheduler', 'command' => 'php artisan schedule:work'],
        ],
        'preview_resources' => [
            ['name' => 'database', 'type' => 'postgresql', 'is_managed' => true],
            ['name' => 'cache', 'type' => 'valkey', 'is_managed' => true],
        ],
        'preview_initialization' => ['command' => 'php artisan db:seed --force'],
        'service_template' => $laravelServiceTemplate,
    ],
    'laravel-api' => [
        'name' => 'Laravel API',
        'description' => 'Laravel API with queues and scheduled jobs.',
        'runtime_type' => 'php',
        'build_command' => null,
        'start_command' => null,
        'container_port' => null,
        'dockerfile_path' => null,
        'processes' => [
            ['name' => 'queue', 'type' => 'worker', 'command' => 'php artisan queue:work --sleep=3 --tries=3 --timeout=90'],
            ['name' => 'scheduler', 'type' => 'scheduler', 'command' => 'php artisan schedule:work'],
        ],
        'preview_resources' => [
            ['name' => 'database', 'type' => 'postgresql', 'is_managed' => true],
            ['name' => 'cache', 'type' => 'valkey', 'is_managed' => true],
        ],
        'preview_initialization' => ['command' => 'php artisan db:seed --force'],
        'service_template' => $laravelServiceTemplate,
    ],
    'wordpress' => [
        'name' => 'WordPress',
        'description' => 'PHP application preset for a Composer-managed WordPress repository.',
        'runtime_type' => 'php',
        'build_command' => null,
        'start_command' => null,
        'container_port' => null,
        'dockerfile_path' => null,
        'processes' => [],
    ],
    'statamic' => [
        'name' => 'Statamic',
        'description' => 'Statamic site with queue and scheduler processes.',
        'runtime_type' => 'php',
        'build_command' => 'npm run build',
        'start_command' => null,
        'container_port' => null,
        'dockerfile_path' => null,
        'processes' => [
            ['name' => 'queue', 'type' => 'worker', 'command' => 'php artisan queue:work --sleep=3 --tries=3'],
            ['name' => 'scheduler', 'type' => 'scheduler', 'command' => 'php artisan schedule:work'],
        ],
    ],
    'node' => [
        'name' => 'Node.js',
        'description' => 'Node service installed with npm and served behind Caddy.',
        'runtime_type' => 'node',
        'build_command' => 'npm run build --if-present',
        'start_command' => 'npm start',
        'container_port' => 3000,
        'dockerfile_path' => null,
        'processes' => [],
        'preview_resources' => [
            ['name' => 'database', 'type' => 'postgresql', 'is_managed' => true],
            ['name' => 'cache', 'type' => 'valkey', 'is_managed' => true],
        ],
        'service_template' => $nodeServiceTemplate,
    ],
    'nextjs' => [
        'name' => 'Next.js',
        'description' => 'Production Next.js server with an optimized build.',
        'runtime_type' => 'node',
        'build_command' => 'npm run build',
        'start_command' => 'npm start',
        'container_port' => 3000,
        'dockerfile_path' => null,
        'processes' => [],
    ],
    'nuxt' => [
        'name' => 'Nuxt',
        'description' => 'Production Nuxt server using the generated Nitro output.',
        'runtime_type' => 'node',
        'build_command' => 'npm run build',
        'start_command' => 'node .output/server/index.mjs',
        'container_port' => 3000,
        'dockerfile_path' => null,
        'processes' => [],
    ],
    'django' => [
        'name' => 'Django',
        'description' => 'Python application installed in an isolated virtual environment.',
        'runtime_type' => 'python',
        'build_command' => 'python manage.py collectstatic --noinput',
        'start_command' => '.venv/bin/gunicorn config.wsgi:application --bind 127.0.0.1:${PORT}',
        'container_port' => 8000,
        'dockerfile_path' => null,
        'processes' => [],
    ],
    'docker' => [
        'name' => 'Dockerfile',
        'description' => 'Build a repository Dockerfile and switch traffic after it becomes healthy.',
        'runtime_type' => 'docker',
        'build_command' => null,
        'start_command' => null,
        'container_port' => 8080,
        'dockerfile_path' => 'Dockerfile',
        'processes' => [],
    ],
    'custom' => [
        'name' => 'Custom PHP',
        'description' => 'Empty PHP runtime configuration.',
        'runtime_type' => 'php',
        'build_command' => null,
        'start_command' => null,
        'container_port' => null,
        'dockerfile_path' => null,
        'processes' => [],
    ],
];
