<?php

return [
    'ssh_connect_timeout' => (int) env('SSH_CONNECT_TIMEOUT', 10),
    'ssh_upload_attempts' => (int) env('SSH_UPLOAD_ATTEMPTS', 3),
    'ssh_retry_delay_ms' => (int) env('SSH_RETRY_DELAY_MS', 1000),
    'ssh_command_timeout' => (int) env('SSH_COMMAND_TIMEOUT', 60),
];
