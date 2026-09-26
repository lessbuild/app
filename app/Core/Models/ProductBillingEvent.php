<?php

namespace App\Core\Models;

use App\Core\Database\CoreModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBillingEvent extends CoreModel
{
    protected $fillable = [
        'workspace_id',
        'product',
        'provider',
        'provider_account_key',
        'provider_event_id',
        'event_type',
        'provider_created_at',
        'processing_status',
        'ignored_reason',
        'processed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'provider_created_at' => 'integer',
            'processed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
