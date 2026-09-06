<?php

return [
    'heartbeat_url' => env('EXTERNAL_MONITOR_HEARTBEAT_URL'),
    'status_url' => env('EXTERNAL_STATUS_URL'),
    'timeout_seconds' => (int) env('EXTERNAL_MONITOR_TIMEOUT', 10),
];
