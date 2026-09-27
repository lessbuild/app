<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MetricSampleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $metric_series_id
 * @property int $telemetry_event_id
 * @property string $time_key
 * @property string|null $start_time_key
 * @property string $value_hash
 * @property string|null $value_text
 * @property float|null $value
 * @property string $state
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $received_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MetricSeries $metricSeries
 * @property-read TelemetryEvent $telemetryEvent
 */
#[Fillable(['metric_series_id', 'telemetry_event_id', 'time_key', 'start_time_key', 'value_hash', 'value_text', 'value', 'state', 'occurred_at', 'received_at'])]
#[Hidden(['value_hash'])]
#[Table(dateFormat: 'Y-m-d H:i:s.u')]
#[UseFactory(MetricSampleFactory::class)]
final class MetricSample extends Model
{
    /** @use HasFactory<MetricSampleFactory> */
    use HasFactory;

    /**
     * The series the sample is a point of.
     *
     * @return BelongsTo<MetricSeries, $this>
     */
    public function metricSeries(): BelongsTo
    {
        return $this->belongsTo(MetricSeries::class);
    }

    /**
     * The ingested event the sample came from.
     *
     * @return BelongsTo<TelemetryEvent, $this>
     */
    public function telemetryEvent(): BelongsTo
    {
        return $this->belongsTo(TelemetryEvent::class);
    }

    /**
     * Plain columns; dates come back as Carbon.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => 'float', 'occurred_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];
    }
}
