<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\MonitorCheck;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MonitorCheck> */
class MonitorCheckFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'monitor_id' => Monitor::factory(), 'config_revision' => 0, 'location' => 'Test checker',
            'status' => 'queued', 'scheduled_at' => now('UTC'), 'lease_until' => now('UTC')->addSeconds(120),
            'skipped_intervals' => 0,
        ];
    }
}
