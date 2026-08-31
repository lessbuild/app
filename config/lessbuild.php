<?php

return [
    'registration' => [
        'enabled' => (bool) env('REGISTRATION_ENABLED', false),
        'allow_first_user' => (bool) env('REGISTRATION_ALLOW_FIRST_USER', true),
    ],
    'ssh_connect_timeout' => (int) env('SSH_CONNECT_TIMEOUT', 10),
    'ssh_upload_attempts' => (int) env('SSH_UPLOAD_ATTEMPTS', 3),
    'ssh_retry_delay_ms' => (int) env('SSH_RETRY_DELAY_MS', 1000),
    'ssh_command_timeout' => (int) env('SSH_COMMAND_TIMEOUT', 60),
    'server_callback_ttl_minutes' => (int) env('SERVER_CALLBACK_TTL_MINUTES', 2880),
    'deployment_callback_ttl_minutes' => (int) env('DEPLOYMENT_CALLBACK_TTL_MINUTES', 360),
    'deployment_log_max_characters' => (int) env('DEPLOYMENT_LOG_MAX_CHARACTERS', 262144),
    'deployment_stale_minutes' => (int) env('DEPLOYMENT_STALE_MINUTES', 10),
    'health_monitor_batch_size' => (int) env('HEALTH_MONITOR_BATCH_SIZE', 20),
    'health_monitor_failure_threshold' => (int) env('HEALTH_MONITOR_FAILURE_THRESHOLD', 3),
    'provider_health_batch_size' => (int) env('PROVIDER_HEALTH_BATCH_SIZE', 20),
    'provider_health_interval_minutes' => (int) env('PROVIDER_HEALTH_INTERVAL_MINUTES', 1440),
    'website_log_max_characters' => (int) env('WEBSITE_LOG_MAX_CHARACTERS', 262144),
    'server_log_max_characters' => (int) env('SERVER_LOG_MAX_CHARACTERS', 262144),
    'server_command_output_max_characters' => (int) env('SERVER_COMMAND_OUTPUT_MAX_CHARACTERS', 262144),
    'database_backup_directory' => env('DATABASE_BACKUP_DIRECTORY') ?: storage_path('app/backups'),
    'database_backup_retention_days' => (int) env('DATABASE_BACKUP_RETENTION_DAYS', 7),
    'prohibit_destructive_database_commands' => (bool) env(
        'DATABASE_PROHIBIT_DESTRUCTIVE_COMMANDS',
        env('APP_ENV', 'production') === 'production',
    ),
    'webhook_max_payload_bytes' => (int) env('WEBHOOK_MAX_PAYLOAD_BYTES', 1048576),
    'webhook_delivery_retention_days' => (int) env('WEBHOOK_DELIVERY_RETENTION_DAYS', 90),
];
