<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\Factories\MonitorCheckFactory;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class MonitorCheck extends Model
{
    /** @use HasFactory<MonitorCheckFactory> */
    use HasFactory, HasUlids;

    protected $hidden = ['processing_token', 'queue_job_uuid', 'evidence', 'scheduled_slot'];

    /** @return BelongsTo<Monitor, $this> */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class)->withTrashed();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'details' => 'array', 'evidence' => 'encrypted:array',
            'config_revision' => 'integer', 'http_status' => 'integer', 'skipped_intervals' => 'integer',
            'duration_ms' => 'float', 'dns_ms' => 'float', 'connect_ms' => 'float', 'ttfb_ms' => 'float',
            'scheduled_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime', 'lease_until' => 'immutable_datetime',
        ];
    }
}
