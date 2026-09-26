<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\Factories\QueueSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class QueueSnapshot extends Model
{
    /** @use HasFactory<QueueSnapshotFactory> */
    use HasFactory;

    protected $hidden = ['payload_hash'];

    /** @return BelongsTo<Monitor, $this> */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class)->withTrashed();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['config_revision' => 'integer', 'applied' => 'boolean',
            'pending' => 'integer', 'delayed' => 'integer', 'reserved' => 'integer', 'failed' => 'integer', 'oldest_wait_seconds' => 'integer',
            'observed_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime', 'valid_until' => 'immutable_datetime'];
    }
}
