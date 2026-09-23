<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\TelemetryEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelemetryEvent>
 */
class TelemetryEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'environment_id' => Environment::factory(),
            'dedupe_key' => hash('sha256', fake()->unique()->uuid()),
            'trace_id' => fake()->sha1(),
            'span_id' => fake()->regexify('[a-f0-9]{16}'),
            'parent_span_id' => null,
            'type' => 'request',
            'severity' => 'info',
            'name' => 'GET /health',
            'route' => '/health',
            'service' => 'app',
            'status_code' => 200,
            'duration_ms' => 12.5,
            'attributes' => [],
            'payload' => [],
            'occurred_at' => now(),
        ];
    }
}
