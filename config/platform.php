<?php

declare(strict_types=1);

// Platform operations: who administers the platform, and the admin panel's limits.
return [
    // Emails granted platform administration by `php artisan platform:admin --import-allowlist`.
    'admin_emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('PLATFORM_ADMIN_EMAILS', ''))))),

    // Minutes an admin's confirmation (password, passkey or social sign-in) lasts in the admin panel.
    'admin_confirmation_seconds' => 900,

    // A queue is unhealthy with more pending jobs than this, or when its oldest is older than this many minutes.
    'queue_backlog_limit' => (int) env('PLATFORM_QUEUE_BACKLOG_LIMIT', 500),
    'queue_oldest_minutes' => (int) env('PLATFORM_QUEUE_OLDEST_MINUTES', 15),

    // The queues the admin panel always lists, even when empty.
    'queues' => ['default', 'checks', 'alerts', 'telemetry', 'terminals'],
];
