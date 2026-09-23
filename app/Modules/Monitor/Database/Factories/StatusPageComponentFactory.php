<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\StatusPage;
use App\Modules\Monitor\Models\StatusPageComponent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StatusPageComponent>
 */
class StatusPageComponentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status_page_id' => StatusPage::factory(),
            'monitor_id' => Monitor::factory(),
            'label' => fake()->words(2, true),
            'position' => 0,
        ];
    }
}
