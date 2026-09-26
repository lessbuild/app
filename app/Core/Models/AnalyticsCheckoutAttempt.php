<?php

namespace App\Core\Models;

use App\Core\Database\CoreModel;

/** Durable idempotency and provider binding for Analytics checkout sessions. */
final class AnalyticsCheckoutAttempt extends CoreModel
{
    protected $table = 'analytics_checkout_attempts';

    protected $fillable = [
        'core_workspace_id',
        'analytics_workspace_id',
        'initiated_by_user_id',
        'provider_account_key',
        'provider_customer_id',
        'provider_checkout_session_id',
        'provider_subscription_id',
        'plan_key',
        'provider_price_id',
        'plan_snapshot',
        'price_terms',
        'price_terms_hash',
        'idempotency_key_hash',
        'request_fingerprint',
        'status',
        'expires_at',
        'completed_at',
        'failure_code',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'plan_snapshot' => 'array',
            'price_terms' => 'array',
        ];
    }
}
