<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Models\StatusPage;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StatusPage>
 */
class StatusPageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->company().' status',
            'slug' => fake()->unique()->slug(),
            'description' => fake()->sentence(),
            'published' => false,
        ];
    }
}
