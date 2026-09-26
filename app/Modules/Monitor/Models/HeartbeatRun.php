<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\Factories\HeartbeatRunFactory;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class HeartbeatRun extends Model
{
    /** @use HasFactory<HeartbeatRunFactory> */
    use HasFactory;

    /** @return BelongsTo<Monitor, $this> */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class)->withTrashed();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['config_revision' => 'integer', 'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime', 'deadline_at' => 'immutable_datetime'];
    }
}
