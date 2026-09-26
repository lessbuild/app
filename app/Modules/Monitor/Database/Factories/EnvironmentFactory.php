<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Environment>
 */
class EnvironmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'name' => 'Production',
            'slug' => 'production',
            'status' => 'active',
            'event_count' => 0,
            'last_seen_at' => null,
        ];
    }
}
