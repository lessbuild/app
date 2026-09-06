<?php

return [
    'temporary_base_domain' => env('TEMPORARY_APP_DOMAIN'),
    'certificate_warning_days' => (int) env('CERTIFICATE_WARNING_DAYS', 21),
    'cloudflare_api_url' => env('CLOUDFLARE_API_URL', 'https://api.cloudflare.com/client/v4'),
];
