<?php

declare(strict_types=1);

// The platform's GitHub App: installations become GitHub providers whose clones use short-lived installation tokens.
return [
    'id' => env('GITHUB_APP_ID'),
    'slug' => env('GITHUB_APP_SLUG'),
    // The App's RSA private key itself, or a file holding it.
    'private_key' => env('GITHUB_APP_PRIVATE_KEY'),
    'private_key_path' => env('GITHUB_APP_PRIVATE_KEY_PATH') ?: storage_path('app/private/github-app-private-key.pem'),
    'webhook_secret' => env('GITHUB_APP_WEBHOOK_SECRET'),
];
