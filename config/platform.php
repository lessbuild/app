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

    // Whether anyone can sign up. When false, people need an access invitation or an account invitation (the first
    // person can always sign up), and /request-access takes requests.
    'registration' => [
        'open' => (bool) env('REGISTRATION_OPEN', true),
        'invitation_days' => max(1, (int) env('REGISTRATION_INVITATION_DAYS', 7)),
    ],

    // Days before declined and accepted access requests are deleted.
    'access_request_retention_days' => 180,

    // Days read notifications stay in inboxes.
    'read_notification_retention_days' => 90,

    // A Monitoring status page (its slug) the operator runs for the platform itself; /status shows it when set.
    'status_page' => env('PLATFORM_STATUS_PAGE'),

    // The queues each service's work runs on, for the public platform status.
    'service_queues' => [
        'deploy' => ['default'],
        'infrastructure' => ['default', 'terminals'],
        'monitoring' => ['checks', 'alerts', 'telemetry'],
        'analytics' => ['default'],
    ],

    // The queues the admin panel always lists, even when empty.
    'queues' => ['default', 'checks', 'alerts', 'telemetry', 'terminals'],
];
