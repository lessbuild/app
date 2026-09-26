<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Data\Telemetry\AlertDeliveryStatus;
use App\Modules\Monitor\Database\Factories\AlertDeliveryAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertDeliveryAttempt extends Model
{
    /** @use HasFactory<AlertDeliveryAttemptFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @return BelongsTo<AlertDelivery, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(AlertDelivery::class, 'alert_delivery_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => AlertDeliveryStatus::class, 'number' => 'integer', 'http_status' => 'integer',
            'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime',
        ];
    }
}
