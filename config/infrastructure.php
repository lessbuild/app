<?php

declare(strict_types=1);

/** Infrastructure: provider checks, server provisioning and SSH. */
return [
    'ssh_connect_timeout' => (int) env('SSH_CONNECT_TIMEOUT', 10),
    'ssh_command_timeout' => (int) env('SSH_COMMAND_TIMEOUT', 60),
    'ssh_upload_attempts' => (int) env('SSH_UPLOAD_ATTEMPTS', 3),
    'ssh_retry_delay_ms' => (int) env('SSH_RETRY_DELAY_MS', 1000),
    'supported_ubuntu_versions' => array_values(array_filter(array_map('trim', explode(',', (string) env('SUPPORTED_UBUNTU_VERSIONS', '22.04,24.04,26.04'))))),
    'server_callback_ttl_minutes' => (int) env('SERVER_CALLBACK_TTL_MINUTES', 2880),
    'website_callback_ttl_minutes' => (int) env('DEPLOYMENT_CALLBACK_TTL_MINUTES', 360),
    'temporary_base_domain' => env('TEMPORARY_APP_DOMAIN'),
    'certificate_warning_days' => 21,
    'server_log_max_characters' => (int) env('SERVER_LOG_MAX_CHARACTERS', 262144),
    'server_command_output_max_characters' => (int) env('SERVER_COMMAND_OUTPUT_MAX_CHARACTERS', 262144),
    'server_command_retention_days' => (int) env('SERVER_COMMAND_RETENTION_DAYS', 180),
    'server_diagnostic_output_max_characters' => 16384,
    'server_diagnostic_lease_seconds' => 180,
    'default_php_version' => (string) env('DEFAULT_PHP_VERSION', '8.4'),
    'cloudflare_api_url' => (string) env('CLOUDFLARE_API_URL', 'https://api.cloudflare.com/client/v4'),
    // How many due providers `providers:check` checks per run (every five minutes).
    'provider_check_batch_size' => (int) env('PROVIDER_CHECK_BATCH_SIZE', 50),
    // The troubleshooting terminal: each open terminal holds one worker on this queue until it closes.
    'terminal' => [
        'connection' => (string) env('TERMINAL_QUEUE_CONNECTION', 'database'),
        'queue' => (string) env('TERMINAL_QUEUE', 'terminals'),
        'session_minutes' => 30,
        'idle_minutes' => 10,
        'poll_milliseconds' => 50,
        'max_input_bytes' => 8192,
        'output_frame_bytes' => 16384,
        'max_pending_output_bytes' => 1048576,
    ],
];
