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

    // Yearly prices (ten months each, so two months free) for accounts that pay yearly. A tier without one can only
    // be bought monthly.
    'prices_yearly' => [
        'deploy' => [
            'tier' => [
                'starter' => env('STRIPE_PRICE_DEPLOY_STARTER_YEARLY'),
                'pro' => env('STRIPE_PRICE_DEPLOY_PRO_YEARLY'),
                'team' => env('STRIPE_PRICE_DEPLOY_TEAM_YEARLY'),
                'business' => env('STRIPE_PRICE_DEPLOY_BUSINESS_YEARLY'),
                'unlimited' => env('STRIPE_PRICE_DEPLOY_UNLIMITED_YEARLY'),
            ],
        ],
        'monitoring' => [
            'tier' => [
                'pro' => env('STRIPE_PRICE_MONITORING_PRO_YEARLY'),
                'team' => env('STRIPE_PRICE_MONITORING_TEAM_YEARLY'),
                'scale' => env('STRIPE_PRICE_MONITORING_SCALE_YEARLY'),
            ],
        ],
        'analytics' => [
            'tier' => [
                'pro' => env('STRIPE_PRICE_ANALYTICS_PRO_YEARLY'),
                'business' => env('STRIPE_PRICE_ANALYTICS_BUSINESS_YEARLY'),
            ],
        ],
        'security' => [
            'tier' => [
                'pro' => env('STRIPE_PRICE_SECURITY_PRO_YEARLY'),
                'team' => env('STRIPE_PRICE_SECURITY_TEAM_YEARLY'),
            ],
        ],
    ],

    // Pay-as-you-go usage beyond a tier's allowance. Each meter needs a Stripe billing meter (its event name here) and a
    // metered price on that meter (under prices.<service>.usage), charged per counted item: $0.50 per 100,000 events is
    // 0.0005 per event, $1 per 10,000 pageviews is 0.01 per pageview. BuildPusher reports only usage beyond the allowance.
    'meters' => [
        'monitoring' => ['events' => env('STRIPE_METER_MONITORING_EVENTS')],
        'analytics' => ['pageviews' => env('STRIPE_METER_ANALYTICS_PAGEVIEWS')],
    ],

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
            'usage' => ['events' => env('STRIPE_PRICE_MONITORING_EVENTS_USAGE')],
        ],
        'analytics' => [
            'tier' => [
                'pro' => env('STRIPE_PRICE_ANALYTICS_PRO'),
                'business' => env('STRIPE_PRICE_ANALYTICS_BUSINESS'),
            ],
            'usage' => ['pageviews' => env('STRIPE_PRICE_ANALYTICS_PAGEVIEWS_USAGE')],
        ],
        'security' => [
            'tier' => [
                'pro' => env('STRIPE_PRICE_SECURITY_PRO'),
                'team' => env('STRIPE_PRICE_SECURITY_TEAM'),
            ],
        ],
    ],
];
