<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\QueueSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QueueSnapshot>
 */
class QueueSnapshotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'monitor_id' => Monitor::factory()->queueMonitor(), 'snapshot_id' => fake()->uuid(), 'config_revision' => 0,
            'payload_hash' => hash('sha256', 'factory-snapshot'), 'applied' => true,
            'observed_at' => now('UTC'), 'received_at' => now('UTC'), 'valid_until' => now('UTC')->addSeconds(180),
            'pending' => 5, 'delayed' => 0, 'reserved' => 1, 'failed' => 0, 'oldest_wait_seconds' => 10,
        ];
    }
}
