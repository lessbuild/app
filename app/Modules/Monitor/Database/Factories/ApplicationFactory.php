<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Application>
 */
class ApplicationFactory extends Factory
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
            'name' => fake()->company(),
            'slug' => fake()->unique()->slug(),
            'framework' => fake()->randomElement(['Laravel', 'Node.js', 'Python', 'Go']),
            'framework_version' => fake()->numerify('##.#'),
            'accent' => fake()->randomElement(['violet', 'sky', 'amber', 'emerald']),
        ];
    }
}
