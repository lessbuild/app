<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Incident;
use App\Models\Monitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Incident> */
class IncidentFactory extends Factory
{
    protected $model = Incident::class;

    public function resolved(): static
    {
        return $this->state(fn (): array => ['active_slot' => null, 'status' => 'resolved', 'resolved_at' => now(), 'closure_reason' => 'recovered']);
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $observation = ['outcome' => 'down', 'reason' => 'unexpected_status', 'http_status' => 503, 'checked_at' => now()->toISOString()];

        return [
            'monitor_id' => Monitor::factory(),
            'account_id' => fn (array $attributes): string => Monitor::query()->whereKey($attributes['monitor_id'])->firstOrFail()->environment->project->account_id,
            'project_id' => fn (array $attributes): string => Monitor::query()->whereKey($attributes['monitor_id'])->firstOrFail()->environment->project_id,
            'active_slot' => true, 'status' => 'open', 'title' => 'Public API health is down',
            'rule_snapshot' => fn (array $attributes): array => Monitor::query()->whereKey($attributes['monitor_id'])->firstOrFail()->snapshot(),
            'opening_observation' => $observation, 'latest_observation' => $observation,
            'opened_at' => now(), 'last_breached_at' => now(),
        ];
    }
}
