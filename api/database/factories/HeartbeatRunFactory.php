<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\HeartbeatRun;
use App\Models\Monitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HeartbeatRun> */
class HeartbeatRunFactory extends Factory
{
    protected $model = HeartbeatRun::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['monitor_id' => Monitor::factory()->heartbeat(), 'run_id' => fake()->uuid(),
            'config_revision' => 0, 'status' => 'running', 'started_at' => now('UTC'),
            'deadline_at' => now('UTC')->addMinutes(5)];
    }
}
