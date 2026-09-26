<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\MonitorModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProjectConnectionEventReceipt extends MonitorModel
{
    protected $fillable = [
        'delivery_id',
        'project_connection_id',
        'handler',
        'payload_hash',
        'deployment_id',
        'processed_at',
    ];

    protected function casts(): array
    {
        return ['processed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Deployment, $this> */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }
}
