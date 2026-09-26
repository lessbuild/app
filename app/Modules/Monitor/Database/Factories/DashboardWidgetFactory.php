<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Models\Dashboard;
use App\Modules\Monitor\Models\DashboardWidget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DashboardWidget>
 */
class DashboardWidgetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dashboard_id' => Dashboard::factory(),
            'type' => 'telemetry',
            'position' => 0,
            'configuration' => null,
        ];
    }
}
