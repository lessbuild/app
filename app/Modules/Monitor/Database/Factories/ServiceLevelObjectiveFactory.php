<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\ServiceLevelObjective;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceLevelObjective>
 */
class ServiceLevelObjectiveFactory extends Factory
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
            'name' => 'Production availability',
            'indicator' => 'availability',
            'service' => null,
            'route' => null,
            'target' => 99.9,
            'window_days' => 30,
            'latency_threshold_ms' => null,
            'status_min' => 200,
            'status_max' => 399,
            'enabled' => true,
        ];
    }
}
