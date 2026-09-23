<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Data\Telemetry\AlertMetric;
use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Models\Environment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AlertRule> */
class AlertRuleFactory extends Factory
{
    public function ready(): static
    {
        return $this->state(fn (): array => ['monitoring_since' => now()->subHours(2), 'next_evaluation_at' => now()->subMinute()]);
    }

    public function paused(): static
    {
        return $this->state(['enabled' => false]);
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'environment_id' => Environment::factory(), 'name' => 'API error rate',
            'metric' => AlertMetric::RequestErrorRate, 'service' => null, 'threshold' => 5,
            'window_minutes' => 5, 'minimum_samples' => 20, 'trigger_checks' => 2, 'recovery_checks' => 2,
            'enabled' => true, 'monitoring_since' => now(), 'next_evaluation_at' => now()->addMinute(),
        ];
    }
}
