<?php

declare(strict_types=1);

return [
    // Signed deployment callbacks stay valid this long; a deploy that runs longer can't report in.
    'callback_ttl_minutes' => (int) env('DEPLOYMENT_CALLBACK_TTL_MINUTES', 360),
    // The deployment log keeps the last this-many characters.
    'deployment_log_max_characters' => (int) env('DEPLOYMENT_LOG_MAX_CHARACTERS', 262144),
    // A running deploy that hasn't reported for this long is failed by `builds:reap`.
    'deployment_stale_minutes' => (int) env('DEPLOYMENT_STALE_MINUTES', 10),
    'webhook_max_payload_bytes' => (int) env('WEBHOOK_MAX_PAYLOAD_BYTES', 1048576),
    'default_php_version' => (string) env('DEFAULT_PHP_VERSION', '8.4'),
];
