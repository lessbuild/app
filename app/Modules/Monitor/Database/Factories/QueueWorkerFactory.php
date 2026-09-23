<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\QueueWorker;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QueueWorker>
 */
class QueueWorkerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'monitor_id' => Monitor::factory()->queueMonitor(), 'worker_id' => fake()->uuid(), 'config_revision' => 0,
            'last_sequence' => 1, 'status' => 'idle', 'job_id' => null, 'job_started_at' => null, 'last_seen_at' => now('UTC'),
        ];
    }
}
