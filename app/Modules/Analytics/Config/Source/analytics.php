<?php

$analyticsBillingPlans = [];
$analyticsBillingPlansJson = env('ANALYTICS_BILLING_PLANS_JSON', '');
if (is_string($analyticsBillingPlansJson) && trim($analyticsBillingPlansJson) !== '') {
    try {
        $decodedAnalyticsBillingPlans = json_decode($analyticsBillingPlansJson, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($decodedAnalyticsBillingPlans) && ! array_is_list($decodedAnalyticsBillingPlans)) {
            $analyticsBillingPlans = $decodedAnalyticsBillingPlans;
        }
    } catch (JsonException) {
        // Invalid catalog JSON is an empty allowlist and cannot enable checkout.
    }
}

return [
    'plan_authority' => env('ANALYTICS_PLAN_AUTHORITY', 'legacy'),
    'billing' => [
        'enabled' => (bool) env('ANALYTICS_BILLING_ENABLED', false),
        'webhooks_enabled' => (bool) env('ANALYTICS_BILLING_WEBHOOKS_ENABLED', false),
        'stripe' => [
            'secret' => env('ANALYTICS_STRIPE_SECRET'),
            'account_id' => env('ANALYTICS_STRIPE_ACCOUNT_ID'),
            'webhook_secret' => env('ANALYTICS_STRIPE_WEBHOOK_SECRET'),
            'api_url' => env('ANALYTICS_STRIPE_API_URL', 'https://api.stripe.com'),
        ],
        // No Analytics prices or commercial terms have been approved. Keep this empty
        // until each allowed provider price and its complete entitlement snapshot exist.
        'plans' => $analyticsBillingPlans,
    ],
    'horizon_enabled' => (bool) env('ANALYTICS_HORIZON_ENABLED', false),
    'platform_status' => [
        'worker_stale_after_seconds' => (int) env('ANALYTICS_WORKER_STALE_AFTER_SECONDS', 180),
    ],
    'event_retention_days' => (int) env('ANALYTICS_EVENT_RETENTION_DAYS', 90),
    'aggregate_retention_months' => (int) env('ANALYTICS_AGGREGATE_RETENTION_MONTHS', 13),
    'export_retention_hours' => (int) env('ANALYTICS_EXPORT_RETENTION_HOURS', 24),
    'invitation_expiry_days' => (int) env('ANALYTICS_INVITATION_EXPIRY_DAYS', 7),
    'visitor_key' => env('ANALYTICS_VISITOR_KEY') ?: env('APP_KEY'),
    'collect_rate_per_minute' => (int) env('ANALYTICS_COLLECT_RATE_PER_MINUTE', 120),
    'allowed_hosts' => array_values(array_filter(array_map(
        static fn (string $host): string => trim($host),
        explode(',', (string) env('ANALYTICS_ALLOWED_HOSTS', 'analytics.buildpusher.com,localhost,127.0.0.1')),
    ), static fn (string $host): bool => $host !== '')),
];
