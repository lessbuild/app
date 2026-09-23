<?php

namespace App\Core\Models;

use App\Core\Database\CoreModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingCustomer extends CoreModel
{
    protected $fillable = [
        'workspace_id',
        'provider',
        'provider_account_key',
        'provider_customer_id',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return HasMany<ProductSubscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(ProductSubscription::class);
    }
}
