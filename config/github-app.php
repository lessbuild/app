<?php

return [
    'id' => env('GITHUB_APP_ID'),
    'slug' => env('GITHUB_APP_SLUG'),
    'private_key' => env('GITHUB_APP_PRIVATE_KEY'),
    'private_key_path' => env('GITHUB_APP_PRIVATE_KEY_PATH') ?: storage_path('app/private/github-app-private-key.pem'),
    'webhook_secret' => env('GITHUB_APP_WEBHOOK_SECRET'),
    'setup_enabled' => env('GITHUB_APP_SETUP_ENABLED', false),
];
