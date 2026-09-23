<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Models\AlertDelivery;
use App\Modules\Monitor\Models\AlertDestination;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AlertDelivery> */
class AlertDeliveryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'alert_destination_id' => AlertDestination::factory(),
            'workspace_id' => fn (array $attributes): int => AlertDestination::query()->findOrFail($attributes['alert_destination_id'])->workspace_id,
            'event' => 'test', 'target_revision' => 0, 'status' => 'queued', 'next_attempt_at' => now('UTC'),
            'generation' => 0, 'attempt_count' => 0, 'cycle_attempts' => 0,
            'payload' => [
                'event' => 'test', 'title' => 'Test notification', 'incident_id' => null,
                'application' => config('app.name').' test', 'environment' => 'Test only', 'url' => null,
                'rule' => null, 'observation' => null, 'opened_at' => null, 'resolved_at' => null,
            ],
        ];
    }
}
