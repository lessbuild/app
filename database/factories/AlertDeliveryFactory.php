<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AlertDelivery;
use App\Models\AlertDestination;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AlertDelivery> */
class AlertDeliveryFactory extends Factory
{
    protected $model = AlertDelivery::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'alert_destination_id' => AlertDestination::factory(),
            'account_id' => fn (array $attributes): string => AlertDestination::query()->whereKey($attributes['alert_destination_id'])->firstOrFail()->account_id,
            'event' => 'test', 'target_revision' => 0, 'status' => 'queued', 'next_attempt_at' => now('UTC'),
            'generation' => 0, 'attempt_count' => 0, 'cycle_attempts' => 0,
            'payload' => [
                'event' => 'test', 'title' => 'Test notification', 'incident_id' => null,
                'application' => config('app.name').' test', 'project' => config('app.name').' test', 'environment' => 'Test only', 'url' => null,
                'rule' => null, 'observation' => null, 'opened_at' => null, 'resolved_at' => null,
            ],
        ];
    }
}
