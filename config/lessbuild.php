<?php

return [
    'ssh_connect_timeout' => (int) env('SSH_CONNECT_TIMEOUT', 10),
    'ssh_upload_attempts' => (int) env('SSH_UPLOAD_ATTEMPTS', 3),
    'ssh_retry_delay_ms' => (int) env('SSH_RETRY_DELAY_MS', 1000),
    'ssh_command_timeout' => (int) env('SSH_COMMAND_TIMEOUT', 60),
    'server_callback_ttl_minutes' => (int) env('SERVER_CALLBACK_TTL_MINUTES', 2880),
    'deployment_callback_ttl_minutes' => (int) env('DEPLOYMENT_CALLBACK_TTL_MINUTES', 360),
    'deployment_log_max_characters' => (int) env('DEPLOYMENT_LOG_MAX_CHARACTERS', 262144),
    'website_log_max_characters' => (int) env('WEBSITE_LOG_MAX_CHARACTERS', 262144),
    'server_log_max_characters' => (int) env('SERVER_LOG_MAX_CHARACTERS', 262144),
];
