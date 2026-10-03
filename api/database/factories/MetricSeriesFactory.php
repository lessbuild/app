<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MetricSeries;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetricSeries>
 */
class MetricSeriesFactory extends Factory
{
    protected $model = MetricSeries::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'environment_id' => fn (): string => MonitorFactory::environment(),
            'identity_hash' => hash('sha256', fake()->unique()->uuid()),
            'source' => 'otlp_metrics', 'name' => 'system.memory.utilization', 'unit' => '1',
            'resource_label' => 'example-host',
            'kind' => 'gauge', 'temporality' => null, 'monotonic' => false,
            'descriptor' => ['resource' => ['host.name' => 'example-host'], 'attributes' => ['state' => 'used'], 'scope' => []],
            'first_received_at' => now(), 'last_received_at' => now(),
        ];
    }
}
