<?php

return [
    'relying_party_id' => env('PLATFORM_PASSKEY_RELYING_PARTY_ID'),
    'allowed_origins' => filled(env('PLATFORM_PASSKEY_ALLOWED_ORIGINS'))
        ? array_values(array_filter(array_map('trim', explode(',', (string) env('PLATFORM_PASSKEY_ALLOWED_ORIGINS')))))
        : [],
    'user_handle_secret' => env('PLATFORM_PASSKEY_USER_HANDLE_SECRET'),
    'timeout' => 60000,
];
