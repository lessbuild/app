<?php

declare(strict_types=1);

/*
| Stripe price IDs per environment. Amounts live in the catalogue (app/Platform/Catalog); these must be the
| matching recurring monthly prices in Stripe. A paid tier with no price ID here can't be bought yet.
*/
return [
    'currency' => 'usd',

    // Days an account's first paid subscription is free for; 0 turns trials off. Later subscriptions start paid.
    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 14),

    // Credit, in cents, that both the referrer and the new account get once the new account's subscription is paid.
    'referral_credit_cents' => (int) env('BILLING_REFERRAL_CREDIT_CENTS', 2000),

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
