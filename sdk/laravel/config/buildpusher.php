<?php

declare(strict_types=1);

return [
    // The environment's ingest key (Monitoring → Setup). Nothing is sent without it.
    'token' => env('BUILDPUSHER_TOKEN'),

    // Where events go.
    'endpoint' => env('BUILDPUSHER_ENDPOINT', 'https://buildpusher.com/api/v1'),

    // The service name and the release (version, tag or commit) events belong to.
    'service' => env('BUILDPUSHER_SERVICE', env('APP_NAME', 'laravel')),
    'release' => env('BUILDPUSHER_RELEASE'),

    // The share of requests recorded (0 to 1). Exceptions, slow queries, failed jobs and logs are always recorded.
    'request_sample_rate' => (float) env('BUILDPUSHER_REQUEST_SAMPLE_RATE', 1.0),

    // Queries at least this slow, in milliseconds, are recorded.
    'slow_query_ms' => (int) env('BUILDPUSHER_SLOW_QUERY_MS', 100),

    // Log messages at this level or above are recorded (debug, info, notice, warning, error, critical, alert, emergency).
    'log_level' => env('BUILDPUSHER_LOG_LEVEL', 'warning'),

    // Request paths never recorded.
    'ignore_paths' => ['up', 'health', 'horizon/*', 'telescope/*', '_debugbar/*', 'livewire/*'],
];
