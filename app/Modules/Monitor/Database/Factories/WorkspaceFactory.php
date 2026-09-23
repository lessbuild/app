<?php

namespace App\Modules\Monitor\Database\Factories;

use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Workspace> */
class WorkspaceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'name' => fake()->company(),
            'slug' => fake()->unique()->uuid(),
            'plan' => 'free',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Workspace $workspace): void {
            $workspace->members()->syncWithoutDetaching([$workspace->owner_id => ['role' => 'owner']]);
        });
    }
}
