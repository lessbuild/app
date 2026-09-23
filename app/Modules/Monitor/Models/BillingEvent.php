<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\Factories\BillingEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'stripe_event_id',
    'event_type',
    'workspace_id',
    'stripe_created_at',
    'processing_status',
    'ignored_reason',
    'processed_at',
])]
class BillingEvent extends Model
{
    public const STATUS_APPLIED = 'applied';

    public const STATUS_IGNORED = 'ignored';

    /** @use HasFactory<BillingEventFactory> */
    use HasFactory;

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'stripe_created_at' => 'integer',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
