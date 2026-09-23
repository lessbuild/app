<?php

namespace App\Core\Models;

use App\Core\Database\CoreModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurrentProductSubscription extends CoreModel
{
    protected $fillable = [
        'workspace_id',
        'product',
        'product_subscription_id',
    ];

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<ProductSubscription, $this> */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ProductSubscription::class, 'product_subscription_id');
    }
}
