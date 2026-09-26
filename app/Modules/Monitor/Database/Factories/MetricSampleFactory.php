<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Models\MetricSample;
use App\Modules\Monitor\Models\MetricSeries;
use App\Modules\Monitor\Models\TelemetryEvent;
use App\Modules\Monitor\Services\Telemetry\MetricProjection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetricSample>
 */
class MetricSampleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'metric_series_id' => MetricSeries::factory(),
            'telemetry_event_id' => fn (array $attributes): int => TelemetryEvent::factory()->create([
                'environment_id' => MetricSeries::findOrFail($attributes['metric_series_id'])->environment_id, 'type' => 'metric',
            ])->id,
            'occurred_at' => now(), 'received_at' => now(),
            'time_key' => fn (array $attributes): string => MetricProjection::timeKey(CarbonImmutable::parse($attributes['occurred_at'])),
            'start_time_key' => null, 'value_hash' => hash('sha256', fake()->uuid()), 'value_text' => '0.75', 'value' => 0.75, 'state' => 'valid',
        ];
    }
}
