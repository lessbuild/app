<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Monitor;
use App\Models\MonitorCheck;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MonitorCheck> */
class MonitorCheckFactory extends Factory
{
    protected $model = MonitorCheck::class;

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
