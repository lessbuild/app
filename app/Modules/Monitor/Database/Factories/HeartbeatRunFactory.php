<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Models\HeartbeatRun;
use App\Modules\Monitor\Models\Monitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HeartbeatRun> */
class HeartbeatRunFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['monitor_id' => Monitor::factory()->heartbeat(), 'run_id' => fake()->uuid(),
            'config_revision' => 0, 'status' => 'running', 'started_at' => now('UTC'),
            'deadline_at' => now('UTC')->addMinutes(5)];
    }
}
