<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Environment;
use App\Models\ServiceLevelObjective;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ServiceLevelObjective> */
class ServiceLevelObjectiveFactory extends Factory
{
    protected $model = ServiceLevelObjective::class;

    /** @return array<string, mixed> */
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
