<?php

namespace App\Modules\Monitor\Models;

use App\Modules\Monitor\Database\Factories\MetricSampleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Modules\Monitor\Database\MonitorModel as Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['metric_series_id', 'telemetry_event_id', 'time_key', 'start_time_key', 'value_hash', 'value_text', 'value', 'state', 'occurred_at', 'received_at'])]
#[Hidden(['value_hash'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
class MetricSample extends Model
{
    /** @use HasFactory<MetricSampleFactory> */
    use HasFactory;

    /** @return BelongsTo<MetricSeries, $this> */
    public function metricSeries(): BelongsTo
    {
        return $this->belongsTo(MetricSeries::class);
    }

    /** @return BelongsTo<TelemetryEvent, $this> */
    public function telemetryEvent(): BelongsTo
    {
        return $this->belongsTo(TelemetryEvent::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['value' => 'float', 'occurred_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];
    }
}
