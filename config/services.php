<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Social sign-in. Callback URL for each: {APP_URL}/auth/{provider}/callback
    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
    ],

    'gitlab' => [
        'client_id' => env('GITLAB_CLIENT_ID'),
        'client_secret' => env('GITLAB_CLIENT_SECRET'),
        'host' => env('GITLAB_HOST', 'https://gitlab.com'),
    ],

    'bitbucket' => [
        'client_id' => env('BITBUCKET_CLIENT_ID'),
        'client_secret' => env('BITBUCKET_CLIENT_SECRET'),
    ],

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    // Cloudflare Turnstile on sign-up; off until both keys are set.
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
    ],

    // SMS and phone-call alerts. Both destination types stay unavailable until all three are set.
    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_FROM'),
        // Most messages or calls to one number in a UTC day, so an alert storm can't run up the bill.
        'daily_limit' => (int) env('TWILIO_DAILY_LIMIT_PER_NUMBER', 50),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
