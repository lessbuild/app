<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

return [
    // The API is token-only: no browser front end authenticates with session cookies.
    'stateful' => [],
    'guard' => [],

    // Per-token expiry is chosen when the token is created.
    'expiration' => null,

    // A recognisable prefix lets secret scanners (GitHub, GitGuardian) spot leaked tokens.
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'bpk_'),

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],
];
