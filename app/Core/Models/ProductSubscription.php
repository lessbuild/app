<?php

namespace App\Core\Models;

use App\Core\Database\CoreModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProductSubscription extends CoreModel
{
    protected $fillable = [
        'workspace_id',
        'billing_customer_id',
        'product',
        'provider',
        'provider_account_key',
        'provider_subscription_id',
        'provider_price_id',
        'plan_key',
        'status',
        'quantity',
        'trial_ends_at',
        'current_period_starts_at',
        'current_period_ends_at',
        'cancel_at',
        'canceled_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'trial_ends_at' => 'datetime',
            'current_period_starts_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'cancel_at' => 'datetime',
            'canceled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<BillingCustomer, $this> */
    public function billingCustomer(): BelongsTo
    {
        return $this->belongsTo(BillingCustomer::class);
    }

    /** @return HasOne<CurrentProductSubscription, $this> */
    public function currentAssignment(): HasOne
    {
        return $this->hasOne(CurrentProductSubscription::class, 'product_subscription_id');
    }
}
