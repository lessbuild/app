<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Models\AlertRule;
use App\Modules\Monitor\Models\Incident;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Incident> */
class IncidentFactory extends Factory
{
    public function resolved(): static
    {
        return $this->state(fn (): array => ['active_slot' => null, 'status' => 'resolved', 'resolved_at' => now(), 'closure_reason' => 'recovered']);
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $observation = ['state' => 'breaching', 'value' => 100, 'samples' => 20, 'from' => now()->subMinutes(6)->toISOString(), 'until' => now()->subMinute()->toISOString()];

        return [
            'alert_rule_id' => AlertRule::factory()->ready(), 'active_slot' => true, 'status' => 'open', 'title' => 'API error rate',
            'rule_snapshot' => fn (array $attributes): array => AlertRule::query()->findOrFail($attributes['alert_rule_id'])->snapshot(),
            'opening_observation' => $observation, 'latest_observation' => $observation,
            'opened_at' => now(), 'last_breached_at' => now(),
        ];
    }
}
