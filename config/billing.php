<?php

declare(strict_types=1);

/*
| Stripe price IDs per environment. Amounts live in the catalogue (app/Platform/Catalog); these must be the
| matching recurring monthly prices in Stripe. A paid tier with no price ID here can't be bought yet.
*/
return [
    'currency' => 'usd',

    'prices' => [
        'deploy' => [
            'tier' => [
                'starter' => env('STRIPE_PRICE_DEPLOY_STARTER'),
                'pro' => env('STRIPE_PRICE_DEPLOY_PRO'),
                'team' => env('STRIPE_PRICE_DEPLOY_TEAM'),
                'business' => env('STRIPE_PRICE_DEPLOY_BUSINESS'),
                'unlimited' => env('STRIPE_PRICE_DEPLOY_UNLIMITED'),
            ],
        ],
        'monitoring' => [
            'tier' => [
                'pro' => env('STRIPE_PRICE_MONITORING_PRO'),
                'team' => env('STRIPE_PRICE_MONITORING_TEAM'),
                'scale' => env('STRIPE_PRICE_MONITORING_SCALE'),
            ],
        ],
        'analytics' => [
            'tier' => [
                'pro' => env('STRIPE_PRICE_ANALYTICS_PRO'),
                'business' => env('STRIPE_PRICE_ANALYTICS_BUSINESS'),
            ],
        ],
    ],
];
